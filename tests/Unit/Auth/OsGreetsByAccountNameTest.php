<?php

declare(strict_types=1);

namespace Semitexa\Os\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Os\Application\Service\OsAdminSession;
use Semitexa\Os\Application\Service\OsPreferences;
use Semitexa\Platform\Settings\Domain\Contract\SettingsStoreInterface;
use Semitexa\Platform\User\Domain\Model\PlatformUser;
use Semitexa\Testing\Traits\BuildsContainerManagedObjects;

/**
 * The console greeted a signed-in person with an empty name.
 *
 * Not because nobody knew it — the identity that had just signed in was
 * carrying a display name the whole time. The name the console greets by is an
 * OS preference, the preference started empty, and nothing ever connected the
 * two.
 */
final class OsGreetsByAccountNameTest extends TestCase
{
    use BuildsContainerManagedObjects;

    private function user(string $displayName, string $email): PlatformUser
    {
        // Through the real constructor: a model reflected into shape breaks the
        // moment the class gains a typed property, which it since did.
        return new PlatformUser(
            id: 'u1',
            email: $email,
            passwordHash: 'x',
            displayName: $displayName,
        );
    }

    /**
     * The real OsAdminSession::seedUserName over a real OsPreferences, with only
     * the settings store faked — that store is an interface, OsPreferences is
     * final, and a test that re-implemented the seeding would have passed with
     * the production code deleted.
     *
     * @return array<string, string> what was written to the store
     */
    private function seed(PlatformUser $user, string $existing): array
    {
        $written = [];

        $store = new class ($existing, $written) implements SettingsStoreInterface {
            /** @param array<string, string> $written */
            public function __construct(private string $existing, private array &$written) {}

            public function get(string $moduleKey, string $key): mixed
            {
                if (array_key_exists($key, $this->written)) {
                    return $this->written[$key];
                }

                return $key === 'user_name' && $this->existing !== '' ? $this->existing : null;
            }

            public function set(string $moduleKey, string $key, mixed $value): void
            {
                $this->written[$key] = (string) $value;
            }

            public function getForUser(string $moduleKey, string $key, string $userId): mixed { return $this->get($moduleKey, $key); }
            public function setForUser(string $moduleKey, string $key, mixed $value, string $userId): void { $this->set($moduleKey, $key, $value); }
            public function claim(string $moduleKey, string $key, mixed $expected, mixed $next): bool { return false; }
            public function getAll(string $moduleKey): array { return $this->written; }
            public function getAllForUser(string $moduleKey, string $userId): array { return $this->written; }
            public function remove(string $moduleKey, string $key): void { unset($this->written[$key]); }
            public function removeForUser(string $moduleKey, string $key, string $userId): void { $this->remove($moduleKey, $key); }
            public function has(string $moduleKey, string $key): bool { return $this->get($moduleKey, $key) !== null; }
            public function hasForUser(string $moduleKey, string $key, string $userId): bool { return $this->has($moduleKey, $key); }
        };

        $prefs = $this->createWithDependencies(OsPreferences::class, ['settings' => $store]);
        $session = $this->createWithDependencies(OsAdminSession::class, ['preferences' => $prefs]);

        (new \ReflectionMethod(OsAdminSession::class, 'seedUserName'))->invoke($session, $user);

        return $written;
    }

    #[Test]
    public function a_signed_in_account_lends_the_console_its_display_name(): void
    {
        $this->assertSame('Тарас', $this->seed($this->user('Тарас', 'taras@example.com'), '')['user_name'] ?? null);
    }

    #[Test]
    public function an_account_with_no_display_name_lends_its_email(): void
    {
        // getDisplayName() already falls back to the email, so the console has
        // something to say either way.
        $this->assertSame('editor@example.com', $this->seed($this->user('', 'editor@example.com'), '')['user_name'] ?? null);
    }

    #[Test]
    public function a_name_the_person_chose_is_never_overwritten(): void
    {
        // set-user-name is how someone says what to call them. Re-applying the
        // account's name on every sign-in would quietly undo that on the next
        // visit.
        $this->assertSame([], $this->seed($this->user('Тарас', 'taras@example.com'), 'Тарасику'));
    }

    #[Test]
    public function signing_in_survives_an_identity_with_no_name_at_all(): void
    {
        $this->assertSame([], $this->seed($this->user('', ''), ''));
    }

    #[Test]
    public function sign_in_seeds_the_name(): void
    {
        // Pins the wiring itself: the seed runs from signIn(), not from some
        // screen that has to remember to ask.
        $source = file_get_contents((new \ReflectionClass(OsAdminSession::class))->getFileName());
        $this->assertIsString($source);
        $this->assertStringContainsString('$this->seedUserName($user);', $source);
    }
}
