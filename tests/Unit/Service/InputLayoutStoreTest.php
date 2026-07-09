<?php

declare(strict_types=1);

namespace Semitexa\Os\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Os\Application\Service\InputHotkey;
use Semitexa\Os\Application\Service\InputLayoutStore;
use Semitexa\Platform\Settings\Domain\Contract\SettingsStoreInterface;

/**
 * The layout store guards the invariants the shell relies on: the pass-through
 * fallback can never be removed (a broken layout must not lock the user out of
 * typing), the active layout is always an enabled one, and corrupt stored
 * JSON degrades to the fallback instead of exploding the boot payload.
 */
final class InputLayoutStoreTest extends TestCase
{
    private InputLayoutStore $store;
    private SettingsStoreInterface $settings;

    protected function setUp(): void
    {
        $this->store = new InputLayoutStore();
        $this->settings = $this->inMemorySettings();
        (new \ReflectionProperty(InputLayoutStore::class, 'settings'))->setValue($this->store, $this->settings);
    }

    #[Test]
    public function a_fresh_install_has_the_pass_through_layout_active_and_the_default_hotkey(): void
    {
        $state = $this->store->state();

        self::assertSame(['en'], array_column($state['layouts'], 'code'));
        self::assertSame('en', $state['active']);
        self::assertSame('Alt+Shift', $state['hotkey_label']);
    }

    #[Test]
    public function adding_a_layout_is_idempotent_and_preserves_order(): void
    {
        $this->store->add('de');
        $this->store->add('uk');
        self::assertSame('Deutsch', $this->store->add('de'));

        self::assertSame(['en', 'de', 'uk'], $this->store->enabledCodes());
    }

    #[Test]
    public function an_unknown_code_is_refused_with_the_supported_list(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Supported:.*Deutsch \(de\)/');

        $this->store->add('xx');
    }

    #[Test]
    public function the_fallback_layout_cannot_be_removed(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->store->remove('en');
    }

    #[Test]
    public function removing_the_active_layout_falls_back_to_pass_through(): void
    {
        $this->store->add('de');
        $this->store->setActive('de');

        $this->store->remove('de');

        self::assertSame(['en'], $this->store->enabledCodes());
        self::assertSame('en', $this->store->activeCode());
    }

    #[Test]
    public function only_an_enabled_layout_can_become_active(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->store->setActive('de'); // in the catalog, but not enabled
    }

    #[Test]
    public function the_hotkey_persists_and_survives_layout_changes(): void
    {
        $combo = InputHotkey::parse('Ctrl+Space');
        self::assertNotNull($combo);
        $this->store->setHotkey($combo);

        $this->store->add('uk');
        $this->store->setActive('uk');

        self::assertSame('Ctrl+Space', $this->store->hotkey()->label());
    }

    #[Test]
    public function corrupt_stored_json_degrades_to_the_fallback_state(): void
    {
        $this->settings->set('os', 'input_layouts', '{not json');

        $state = $this->store->state();

        self::assertSame(['en'], array_column($state['layouts'], 'code'));
        self::assertSame('en', $state['active']);
    }

    #[Test]
    public function a_stored_code_that_left_the_catalog_is_dropped_silently(): void
    {
        $this->settings->set('os', 'input_layouts', (string) json_encode([
            'layouts' => ['en', 'zz', 'de'],
            'active' => 'zz',
            'hotkey' => ['modifiers' => ['alt', 'shift'], 'key' => null],
        ]));

        self::assertSame(['en', 'de'], $this->store->enabledCodes());
        self::assertSame('en', $this->store->activeCode());
    }

    #[Test]
    public function state_carries_full_keymaps_for_the_shell(): void
    {
        $this->store->add('uk');

        $state = $this->store->state();
        $uk = null;
        foreach ($state['layouts'] as $layout) {
            if ($layout['code'] === 'uk') {
                $uk = $layout;
            }
        }

        self::assertNotNull($uk);
        self::assertSame('й', $uk['keymap']['normal']['KeyQ']);
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
