<?php

declare(strict_types=1);

namespace Semitexa\Os\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Session\SessionInterface;
use Semitexa\Os\Application\Payload\Session\OsAdminSessionSegment;
use Semitexa\Platform\User\Domain\Enum\UserRole;
use Semitexa\Platform\User\Domain\Enum\UserStatus;
use Semitexa\Os\Application\Handler\PayloadHandler\PasswordChangeHandler;
use Semitexa\Os\Application\Payload\Request\PasswordChangePayload;
use Semitexa\Os\Application\Service\OsAdminSession;
use Semitexa\Platform\User\Application\Service\PasswordHasher;
use Semitexa\Platform\User\Domain\Contract\PlatformUserRepositoryInterface;
use Semitexa\Platform\User\Domain\Model\PlatformUser;
use Semitexa\Testing\Traits\BuildsContainerManagedObjects;

/**
 * Changing a password used to mean asking an operator to run a CLI command,
 * which makes the operator a keylogger by protocol: the only way to get a new
 * password was for someone else to choose it and tell you.
 *
 * The check that matters is the current password. A session is a bearer token —
 * without it, a borrowed one could lock the owner out of their own account, and
 * the owner's own password would be the thing that stopped working.
 */
final class PasswordChangeTest extends TestCase
{
    use BuildsContainerManagedObjects;

    private ?PlatformUser $saved = null;

    private function user(string $password): PlatformUser
    {
        return new PlatformUser(
            id: 'u1',
            email: 'taras@example.com',
            passwordHash: password_hash($password, PASSWORD_BCRYPT),
            displayName: 'Тарас',
            role: UserRole::Admin,
            status: UserStatus::Active,
        );
    }

    /**
     * A real OsAdminSession over a fake repository and a session double.
     *
     * OsAdminSession is final, so the resolution under test — segment says
     * signed in, repository stands behind the id — runs for real rather than
     * being replaced by a stub that would agree with whatever I wrote.
     */
    private function handler(?PlatformUser $current): array
    {
        $this->saved = null;

        $users = new class ($current, $this->saved) implements PlatformUserRepositoryInterface {
            public function __construct(private ?PlatformUser $current, private ?PlatformUser &$saved) {}
            public function update(PlatformUser $user): void { $this->saved = $user; }
            public function save(PlatformUser $user): void { $this->saved = $user; }
            public function findById(string $id): ?PlatformUser { return $this->current; }
            public function findByEmail(string $email, ?string $tenantId = null): ?PlatformUser { return $this->current; }
            public function findAll(?string $tenantId = null, bool $includeDisabled = false): array { return []; }
            public function delete(string $id): void {}
        };

        $admins = $this->createWithDependencies(OsAdminSession::class, ['users' => $users]);

        $segment = new OsAdminSessionSegment();
        if ($current !== null) {
            $segment->signIn('u1');
        }

        $session = new class ($segment) implements SessionInterface {
            public bool $regenerated = false;
            public function __construct(private OsAdminSessionSegment $segment) {}
            public function getPayload(string $payloadClass): object { return $this->segment; }
            public function setPayload(object $payload): void {}
            public function regenerate(): void { $this->regenerated = true; }
            public function get(string $key, mixed $default = null): mixed { return $default; }
            public function set(string $key, mixed $value): void {}
            public function has(string $key): bool { return false; }
            public function remove(string $key): void {}
            public function clear(): void {}
            public function getId(): string { return 'sess'; }
            public function flash(string $key, mixed $value): void {}
            public function getFlash(string $key, mixed $default = null): mixed { return $default; }
            public function save(): void {}
        };

        $handler = $this->createWithDependencies(PasswordChangeHandler::class, [
            'admins' => $admins,
            'users' => $users,
            'hasher' => new PasswordHasher(),
        ]);
        (new \ReflectionProperty(PasswordChangeHandler::class, 'session'))->setValue($handler, $session);

        return [$handler, $session];
    }

    private function payload(string $current, string $new): PasswordChangePayload
    {
        $p = new PasswordChangePayload();
        $p->setCurrentPassword($current);
        $p->setNewPassword($new);
        $p->setRepeatPassword($new);

        return $p;
    }

    /** @return array{status: int, body: array<string, mixed>, rotated: bool} */
    private function change(?PlatformUser $user, string $current, string $new): array
    {
        [$handler, $session] = $this->handler($user);
        $resource = $handler->handle($this->payload($current, $new), new ResourceResponse());

        return [
            'status' => $resource->getStatusCode(),
            'body' => (array) json_decode((string) $resource->getContent(), true),
            'rotated' => $session->regenerated,
        ];
    }

    #[Test]
    public function the_wrong_current_password_changes_nothing(): void
    {
        $out = $this->change($this->user('correct-horse-battery'), 'not-my-password', 'a-new-long-password');

        $this->assertSame(422, $out['status']);
        $this->assertNull($this->saved, 'a refused attempt must not reach the repository');
    }

    #[Test]
    public function the_right_current_password_sets_a_new_one(): void
    {
        $out = $this->change($this->user('correct-horse-battery'), 'correct-horse-battery', 'a-new-long-password');

        $this->assertSame(200, $out['status']);
        $this->assertNotNull($this->saved);
        $this->assertTrue($this->saved->verifyPassword('a-new-long-password'));
        $this->assertFalse($this->saved->verifyPassword('correct-horse-battery'), 'the old password must stop working');

        // A password change is exactly the moment someone acts on a suspicion
        // that an old session is not theirs alone.
        $this->assertTrue($out['rotated'], 'the session id must be rotated');
    }

    #[Test]
    public function a_password_shorter_than_the_policy_is_refused_in_the_policys_own_words(): void
    {
        // PasswordHasher owns the rule; duplicating it here would let the two
        // drift and the person would read whichever copy was older.
        $out = $this->change($this->user('correct-horse-battery'), 'correct-horse-battery', 'short');

        $this->assertSame(422, $out['status']);
        $this->assertStringContainsString(
            (string) PasswordHasher::MIN_LENGTH,
            json_encode($out['body'], JSON_UNESCAPED_SLASHES),
        );
        $this->assertNull($this->saved);
    }

    #[Test]
    public function setting_the_same_password_again_is_refused(): void
    {
        $out = $this->change($this->user('correct-horse-battery'), 'correct-horse-battery', 'correct-horse-battery');

        $this->assertSame(422, $out['status']);
        $this->assertNull($this->saved);
    }

    #[Test]
    public function a_caller_with_no_session_is_told_to_sign_in(): void
    {
        $out = $this->change(null, 'anything', 'a-new-long-password');

        $this->assertSame(401, $out['status']);
        $this->assertNull($this->saved);
    }

    #[Test]
    public function the_form_catches_a_mistyped_repeat_before_anything_is_hashed(): void
    {
        $p = new PasswordChangePayload();
        $p->setCurrentPassword('correct-horse-battery');
        $p->setNewPassword('a-new-long-password');
        $p->setRepeatPassword('a-new-long-passvord');

        $this->assertArrayHasKey('repeatPassword', $p->validate());
    }
}
