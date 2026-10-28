<?php

declare(strict_types=1);

namespace Semitexa\Os\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Session\SessionInterface;
use Semitexa\Os\Application\Handler\PayloadHandler\SettingsAppHandler;
use Semitexa\Os\Application\Payload\Request\SettingsAppPayload;
use Semitexa\Os\Application\Payload\Session\OsAdminSessionSegment;
use Semitexa\Os\Application\Service\OsAdminSession;
use Semitexa\Os\Application\Service\OsPreferences;
use Semitexa\Platform\Settings\Domain\Contract\SettingsStoreInterface;
use Semitexa\Platform\User\Domain\Contract\PlatformUserRepositoryInterface;
use Semitexa\Platform\User\Domain\Model\PlatformUser;
use Semitexa\Testing\Traits\BuildsContainerManagedObjects;

/**
 * POST /os/password had no form. A user whose password an operator issued was
 * sent to Settings after signing in, and the console opened nothing else for
 * them until they replaced it: they could not.
 */
final class SettingsPasswordFormTest extends TestCase
{
    use BuildsContainerManagedObjects;

    #[Test]
    public function a_signed_in_account_gets_the_password_form(): void
    {
        $html = $this->render(new PlatformUser(id: 'u1', email: 'a@example.test', passwordHash: 'x', tenantId: 'museum'));

        self::assertStringContainsString('id="password-form" data-must-change="0"', $html);
        self::assertStringContainsString('autocomplete="current-password"', $html);
        self::assertStringNotContainsString('class="notice"', $html);
    }

    #[Test]
    public function an_issued_password_is_asked_to_be_replaced(): void
    {
        $html = $this->render(new PlatformUser(id: 'u1', email: 'a@example.test', passwordHash: 'x', tenantId: 'museum', passwordIssuedByOperator: true));

        self::assertStringContainsString('data-must-change="1"', $html);
        self::assertStringContainsString('Your password was set by your operator', $html);
    }

    #[Test]
    public function nobody_signed_in_has_no_password_to_change(): void
    {
        $html = $this->render(null);

        self::assertStringContainsString('<script type="application/json" id="settings-names">', $html, 'the page itself rendered');
        self::assertStringNotContainsString('password-form', $html);
    }

    private function render(?PlatformUser $user): string
    {
        $handler = new SettingsAppHandler();
        (new \ReflectionProperty($handler, 'prefs'))->setValue($handler, $this->createWithDependencies(OsPreferences::class, ['settings' => self::settings()]));
        $users = new class ($user) implements PlatformUserRepositoryInterface {
            public function __construct(private ?PlatformUser $user) {}
            public function update(PlatformUser $user): void {}
            public function save(PlatformUser $user): void {}
            public function findById(string $id): ?PlatformUser { return $this->user; }
            public function findByEmail(string $email, ?string $tenantId = null): ?PlatformUser { return $this->user; }
            public function findAll(?string $tenantId = null, bool $includeDisabled = false): array { return []; }
            public function delete(string $id): void {}
        };
        (new \ReflectionProperty($handler, 'admins'))->setValue($handler, $this->createWithDependencies(OsAdminSession::class, ['users' => $users]));
        $segment = new OsAdminSessionSegment();
        if ($user !== null) {
            $segment->signIn('u1');
        }
        (new \ReflectionProperty($handler, 'session'))->setValue($handler, new class ($segment) implements SessionInterface {
            public function __construct(private OsAdminSessionSegment $segment) {}
            public function getPayload(string $payloadClass): object { return $payloadClass === OsAdminSessionSegment::class ? $this->segment : new $payloadClass(); }
            public function setPayload(object $payload): void {}
            public function regenerate(): void {}
            public function get(string $key, mixed $default = null): mixed { return $default; }
            public function set(string $key, mixed $value): void {}
            public function has(string $key): bool { return false; }
            public function remove(string $key): void {}
            public function clear(): void {}
            public function getId(): string { return 'sess'; }
            public function flash(string $key, mixed $value): void {}
            public function getFlash(string $key, mixed $default = null): mixed { return $default; }
            public function save(): void {}
        });

        return (string) $handler->handle(new SettingsAppPayload(), new ResourceResponse())->getContent();
    }

    private static function settings(): SettingsStoreInterface
    {
        return new class implements SettingsStoreInterface {
            public function get(string $moduleKey, string $key): mixed { return null; }
            public function set(string $moduleKey, string $key, mixed $value): void {}
            public function getForUser(string $moduleKey, string $key, string $userId): mixed { return null; }
            public function setForUser(string $moduleKey, string $key, mixed $value, string $userId): void {}
            public function claim(string $moduleKey, string $key, mixed $expected, mixed $next): bool { return false; }
            public function getAll(string $moduleKey): array { return []; }
            public function getAllForUser(string $moduleKey, string $userId): array { return []; }
            public function remove(string $moduleKey, string $key): void {}
            public function removeForUser(string $moduleKey, string $key, string $userId): void {}
            public function has(string $moduleKey, string $key): bool { return false; }
            public function hasForUser(string $moduleKey, string $key, string $userId): bool { return false; }
        };
    }
}
