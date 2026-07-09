<?php

declare(strict_types=1);

namespace Semitexa\Os\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Os\Application\Service\AddInputLayoutSkill;
use Semitexa\Os\Application\Service\InputLayoutCatalog;
use Semitexa\Os\Application\Service\InputLayoutStore;
use Semitexa\Os\Application\Service\RemoveInputLayoutSkill;
use Semitexa\Os\Application\Service\SetLayoutHotkeySkill;
use Semitexa\Platform\Settings\Domain\Contract\SettingsStoreInterface;

/**
 * The chat surface of layout management ("додай німецьку розкладку",
 * "переключай по Ctrl+Space"). Pins that loose language phrasing lands in the
 * store, that failure modes come back as guidance instead of exceptions, and
 * that the fallback layout survives a removal attempt.
 */
final class InputLayoutSkillsTest extends TestCase
{
    private InputLayoutStore $store;

    protected function setUp(): void
    {
        $this->store = new InputLayoutStore();
        (new \ReflectionProperty(InputLayoutStore::class, 'settings'))
            ->setValue($this->store, $this->inMemorySettings());
    }

    /** Minimal in-memory settings double — the store only uses get/set. */
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

    #[Test]
    public function add_skill_enables_a_layout_from_ukrainian_phrasing(): void
    {
        $reply = $this->addSkill()->invoke(['language' => 'німецька']);

        self::assertStringContainsString('Deutsch', $reply);
        self::assertSame(['en', 'de'], $this->store->enabledCodes());
    }

    #[Test]
    public function add_skill_lists_supported_layouts_on_an_unknown_language(): void
    {
        $reply = $this->addSkill()->invoke(['language' => 'клінгонська']);

        self::assertStringContainsString('Available:', $reply);
        self::assertSame(['en'], $this->store->enabledCodes());
    }

    #[Test]
    public function add_skill_mentions_the_switch_hotkey_so_the_user_learns_it(): void
    {
        $reply = $this->addSkill()->invoke(['language' => 'ukrainian']);

        self::assertStringContainsString('Alt+Shift', $reply);
    }

    #[Test]
    public function remove_skill_disables_a_layout_but_refuses_the_fallback(): void
    {
        $this->store->add('de');

        $removed = $this->removeSkill()->invoke(['language' => 'german']);
        self::assertStringContainsString('Deutsch', $removed);
        self::assertSame(['en'], $this->store->enabledCodes());

        $refused = $this->removeSkill()->invoke(['language' => 'english']);
        self::assertStringContainsString('cannot be removed', $refused);
        self::assertSame(['en'], $this->store->enabledCodes());
    }

    #[Test]
    public function hotkey_skill_parses_and_persists_a_combo(): void
    {
        $reply = $this->hotkeySkill()->invoke(['combo' => 'ctrl пробіл']);

        self::assertStringContainsString('Ctrl+Space', $reply);
        self::assertSame('Ctrl+Space', $this->store->hotkey()->label());
    }

    #[Test]
    public function hotkey_skill_rejects_gibberish_with_guidance_and_keeps_the_old_combo(): void
    {
        $reply = $this->hotkeySkill()->invoke(['combo' => 'q+w+e']);

        self::assertStringContainsString('Ctrl', $reply); // the guidance names valid modifiers
        self::assertSame('Alt+Shift', $this->store->hotkey()->label());
    }

    private function addSkill(): AddInputLayoutSkill
    {
        $skill = new AddInputLayoutSkill();
        (new \ReflectionProperty(AddInputLayoutSkill::class, 'store'))->setValue($skill, $this->store);
        (new \ReflectionProperty(AddInputLayoutSkill::class, 'catalog'))->setValue($skill, new InputLayoutCatalog());

        return $skill;
    }

    private function removeSkill(): RemoveInputLayoutSkill
    {
        $skill = new RemoveInputLayoutSkill();
        (new \ReflectionProperty(RemoveInputLayoutSkill::class, 'store'))->setValue($skill, $this->store);
        (new \ReflectionProperty(RemoveInputLayoutSkill::class, 'catalog'))->setValue($skill, new InputLayoutCatalog());

        return $skill;
    }

    private function hotkeySkill(): SetLayoutHotkeySkill
    {
        $skill = new SetLayoutHotkeySkill();
        (new \ReflectionProperty(SetLayoutHotkeySkill::class, 'store'))->setValue($skill, $this->store);

        return $skill;
    }
}
