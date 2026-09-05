<?php

declare(strict_types=1);

namespace Semitexa\Os\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Locale\Context\LocaleContextStore;
use Semitexa\Os\Application\Service\OsPreferences;
use Semitexa\Platform\Settings\Domain\Contract\SettingsStoreInterface;

/**
 * She has one name and two spellings. Which one a fresh install shows follows
 * the console's language, decided once in {@see OsPreferences::language()} — the
 * same answer the shell's string bundle is built from, so the greeting and the
 * name in it can never come out in different alphabets.
 *
 * A name the operator typed always wins: the default is what fills the gap
 * before anyone has an opinion, not a value that keeps re-asserting itself.
 */
final class OsAssistantDefaultNameTest extends TestCase
{
    private OsPreferences $prefs;
    private string $localeBefore;

    /** @var string[] */
    private array $supportedBefore;

    protected function setUp(): void
    {
        $this->localeBefore = LocaleContextStore::getLocale();
        $this->supportedBefore = LocaleContextStore::getSupportedLocales();
        LocaleContextStore::setSupportedLocales(['en', 'uk']);

        $this->prefs = new OsPreferences();
        (new \ReflectionProperty(OsPreferences::class, 'settings'))->setValue($this->prefs, $this->inMemorySettings());
        (new \ReflectionProperty(OsPreferences::class, 'localeOverride'))->setValue($this->prefs, '');
    }

    protected function tearDown(): void
    {
        // Static context: leaving 'uk' behind would rename the assistant for
        // every test that runs after this file.
        LocaleContextStore::setLocale($this->localeBefore);
        LocaleContextStore::setSupportedLocales($this->supportedBefore);
    }

    #[Test]
    public function a_ukrainian_console_gets_her_name_in_cyrillic(): void
    {
        LocaleContextStore::setLocale('uk');

        self::assertSame('Соломія', $this->prefs->assistantName());
    }

    #[Test]
    public function every_other_console_gets_the_latin_spelling(): void
    {
        LocaleContextStore::setLocale('en');

        self::assertSame('Solomiia', $this->prefs->assistantName());
    }

    #[Test]
    public function the_stored_preference_wins_over_the_request_locale(): void
    {
        LocaleContextStore::setLocale('en');
        $this->prefs->setLocale('uk');

        self::assertSame('uk', $this->prefs->language());
        self::assertSame('Соломія', $this->prefs->assistantName());
    }

    #[Test]
    public function the_install_wide_console_language_is_used_when_nothing_is_stored(): void
    {
        LocaleContextStore::setLocale('en');
        (new \ReflectionProperty(OsPreferences::class, 'localeOverride'))->setValue($this->prefs, 'uk');

        self::assertSame('Соломія', $this->prefs->assistantName());
    }

    #[Test]
    public function a_name_the_operator_chose_is_never_overwritten_by_the_language(): void
    {
        $this->prefs->setAssistantName('Jarvis');

        LocaleContextStore::setLocale('uk');
        self::assertSame('Jarvis', $this->prefs->assistantName());

        LocaleContextStore::setLocale('en');
        self::assertSame('Jarvis', $this->prefs->assistantName());
    }

    private function inMemorySettings(): SettingsStoreInterface
    {
        return new class implements SettingsStoreInterface {
            /** @var array<string, mixed> */
            private array $data = [];

            public function get(string $moduleKey, string $key): mixed
            {
                return $this->data[$moduleKey . '/' . $key] ?? null;
            }

            public function getForUser(string $moduleKey, string $key, string $userId): mixed
            {
                return null;
            }

            public function set(string $moduleKey, string $key, mixed $value): void
            {
                $this->data[$moduleKey . '/' . $key] = $value;
            }

            public function setForUser(string $moduleKey, string $key, mixed $value, string $userId): void
            {
            }

            public function claim(string $moduleKey, string $key, mixed $expected, mixed $next): bool
            {
                if (($this->data[$moduleKey . '/' . $key] ?? null) === $expected) {
                    $this->data[$moduleKey . '/' . $key] = $next;

                    return true;
                }

                return false;
            }

            public function getAll(string $moduleKey): array
            {
                return [];
            }

            public function getAllForUser(string $moduleKey, string $userId): array
            {
                return [];
            }

            public function remove(string $moduleKey, string $key): void
            {
                unset($this->data[$moduleKey . '/' . $key]);
            }

            public function removeForUser(string $moduleKey, string $key, string $userId): void
            {
            }

            public function has(string $moduleKey, string $key): bool
            {
                return \array_key_exists($moduleKey . '/' . $key, $this->data);
            }

            public function hasForUser(string $moduleKey, string $key, string $userId): bool
            {
                return false;
            }
        };
    }
}
