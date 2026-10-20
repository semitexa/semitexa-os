<?php

declare(strict_types=1);

namespace Semitexa\Os\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Session\SessionInterface;
use Semitexa\Core\Support\PayloadSerializer;
use Semitexa\Os\Application\Handler\PayloadHandler\PasswordChangeHandler;
use Semitexa\Os\Application\Payload\Request\PasswordChangePayload;
use Semitexa\Os\Application\Payload\Session\OsAdminSessionSegment;
use Semitexa\Os\Application\Service\OsAdminSession;
use Semitexa\Platform\User\Application\Service\PasswordHasher;
use Semitexa\Platform\User\Domain\Contract\PlatformUserRepositoryInterface;
use Semitexa\Platform\User\Domain\Enum\UserRole;
use Semitexa\Platform\User\Domain\Model\PlatformUser;
use Semitexa\Testing\Traits\BuildsContainerManagedObjects;

/**
 * A new password ends the account's other sessions (tk-password-change-ends-sessions).
 *
 * Before, OsAdminSession::current() only asked whether the account still
 * existed and could sign in, so a session someone else held survived the very
 * change or reset its owner made to get rid of it.
 */
final class NewPasswordEndsOtherSessionsTest extends TestCase
{
    use BuildsContainerManagedObjects;

    private const PASSWORD = 'correct-horse-battery';

    private PlatformUserRepositoryInterface $users;
    private OsAdminSession $admins;

    protected function setUp(): void
    {
        $this->users = new class (self::user()) implements PlatformUserRepositoryInterface {
            public function __construct(public PlatformUser $user) {}
            public function update(PlatformUser $user): void { $this->user = $user; }
            public function save(PlatformUser $user): void { $this->user = $user; }
            public function findById(string $id): ?PlatformUser { return $this->user->getId() === $id ? $this->user : null; }
            public function findByEmail(string $email, ?string $tenantId = null): ?PlatformUser { return $this->user; }
            public function findAll(?string $tenantId = null, bool $includeDisabled = false): array { return [$this->user]; }
            public function delete(string $id): void {}
        };
        $this->admins = $this->createWithDependencies(OsAdminSession::class, ['users' => $this->users]);
    }

    #[Test]
    public function a_session_signed_in_before_a_new_password_is_over(): void
    {
        $other = $this->signedIn();

        $this->users->update($this->users->findById('u1')->withPasswordHash(password_hash('a-new-long-password', PASSWORD_BCRYPT)));

        self::assertNull($this->admins->current($other));
        self::assertFalse($other->getPayload(OsAdminSessionSegment::class)->isSignedIn(), 'the session is signed out, not just refused once');
    }

    #[Test]
    public function a_session_signed_in_after_the_new_password_holds(): void
    {
        $this->users->update($this->users->findById('u1')->withPasswordHash(password_hash('a-new-long-password', PASSWORD_BCRYPT)));

        $fresh = $this->signedIn();

        self::assertSame('u1', $this->admins->current($fresh)?->getId());
        self::assertSame(1, $fresh->getPayload(OsAdminSessionSegment::class)->getCredentialVersion());
    }

    #[Test]
    public function the_session_that_changes_the_password_keeps_going_and_the_others_end(): void
    {
        $mine = $this->signedIn();
        $theirs = $this->signedIn();

        $handler = $this->createWithDependencies(PasswordChangeHandler::class, [
            'admins' => $this->admins,
            'users' => $this->users,
            'hasher' => new PasswordHasher(),
        ]);
        (new \ReflectionProperty(PasswordChangeHandler::class, 'session'))->setValue($handler, $mine);
        $mine->regenerated = false; // signing in above rotated it already
        $payload = new PasswordChangePayload();
        $payload->setCurrentPassword(self::PASSWORD);
        $payload->setNewPassword('a-new-long-password');
        $payload->setRepeatPassword('a-new-long-password');

        self::assertSame(200, $handler->handle($payload, new ResourceResponse())->getStatusCode());
        self::assertTrue($mine->regenerated, 'the changing session is on a new id');
        self::assertSame('u1', $this->admins->current($mine)?->getId());
        self::assertNull($this->admins->current($theirs));
    }

    #[Test]
    public function a_rehash_on_sign_in_is_not_a_new_password(): void
    {
        $session = $this->signedIn();

        $this->users->update($this->users->findById('u1')->withRehashedPassword(password_hash(self::PASSWORD, PASSWORD_ARGON2ID)));

        self::assertSame('u1', $this->admins->current($session)?->getId());
    }

    #[Test]
    public function a_session_from_before_the_field_holds_while_the_password_is_unchanged(): void
    {
        // A session written before credentialVersion existed rehydrates with 0,
        // which is what every account starts at: the deploy signs nobody out.
        // Through the hydration a stored session really takes.
        $legacy = PayloadSerializer::hydrate(new OsAdminSessionSegment(), ['userId' => 'u1', 'signedInAt' => time()]);
        self::assertSame('u1', $this->admins->current(self::session($legacy))?->getId());

        $this->users->update($this->users->findById('u1')->withPasswordHash(password_hash('a-new-long-password', PASSWORD_BCRYPT)));
        $stored = PayloadSerializer::hydrate(new OsAdminSessionSegment(), ['userId' => 'u1', 'signedInAt' => time(), 'credentialVersion' => 1]);
        self::assertSame(1, $stored->getCredentialVersion(), 'the version survives a round trip through the session store');
        self::assertSame('u1', $this->admins->current(self::session($stored))?->getId());
        self::assertNull($this->admins->current(self::session(PayloadSerializer::hydrate(new OsAdminSessionSegment(), ['userId' => 'u1', 'signedInAt' => time()]))));
    }

    private function signedIn(): object
    {
        $session = self::session(new OsAdminSessionSegment());
        $this->admins->signIn($session, $this->users->findById('u1'));

        return $session;
    }

    private static function user(): PlatformUser
    {
        return new PlatformUser(
            id: 'u1',
            email: 'owner@example.test',
            passwordHash: password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]),
            displayName: 'Owner',
            role: UserRole::Admin,
        );
    }

    private static function session(OsAdminSessionSegment $segment): SessionInterface
    {
        return new class ($segment) implements SessionInterface {
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
    }
}
