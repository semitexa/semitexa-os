<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Platform\Settings\Application\Service\SettingsStore;
use Semitexa\Platform\Settings\Domain\Contract\SettingsStoreInterface;

/**
 * Which keyboard layouts the OS has enabled, which one is active, and the
 * switch hotkey. Persisted like {@see OsPreferences} / {@see SkinStore}
 * (settings store, module 'os'). Only catalog codes are stored — keymaps come
 * from {@see InputLayoutCatalog} at read time, so shipped keymap fixes reach
 * existing installs without a data migration.
 *
 * English (pass-through) is always present and always first: with it removed
 * a broken custom layout could lock the user out of typing commands to fix it.
 */
#[AsService]
final class InputLayoutStore
{
    private const MODULE = 'os';
    private const KEY = 'input_layouts';
    private const FALLBACK = 'en';

    #[InjectAsReadonly]
    protected SettingsStoreInterface $settings;

    private ?InputLayoutCatalog $catalog = null;

    /**
     * The full client-facing state: enabled layouts (with keymaps), the active
     * code, and the hotkey — safe to hand straight to the shell.
     *
     * @return array{layouts: list<array{code: string, label: string, short: string, keymap: array{normal: array<string, string>, shift: array<string, string>, altgr: array<string, string>}}>, active: string, hotkey: array{modifiers: list<string>, key: string|null}, hotkey_label: string}
     */
    public function state(): array
    {
        $hotkey = $this->hotkey();

        return [
            'layouts' => array_values(array_filter(array_map(
                fn (string $code): ?array => $this->catalog()->get($code),
                $this->enabledCodes(),
            ))),
            'active' => $this->activeCode(),
            'hotkey' => $hotkey->toArray(),
            'hotkey_label' => $hotkey->label(),
        ];
    }

    /** @return list<string> enabled catalog codes, fallback layout always first */
    public function enabledCodes(): array
    {
        $raw = (array) ($this->raw()['layouts'] ?? []);
        $codes = [self::FALLBACK];
        foreach ($raw as $code) {
            if (is_string($code) && $code !== self::FALLBACK && $this->catalog()->has($code)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    public function activeCode(): string
    {
        $active = $this->raw()['active'] ?? null;

        return is_string($active) && in_array($active, $this->enabledCodes(), true) ? $active : self::FALLBACK;
    }

    public function hotkey(): InputHotkey
    {
        $stored = $this->raw()['hotkey'] ?? null;

        return is_array($stored) ? InputHotkey::fromArray($stored) : InputHotkey::default();
    }

    /**
     * Enable a catalog layout. Idempotent; returns the layout label.
     *
     * @throws \InvalidArgumentException on a code outside the catalog
     */
    public function add(string $code): string
    {
        $layout = $this->catalog()->get($code);
        if ($layout === null) {
            throw new \InvalidArgumentException('Unknown layout: ' . $code . '. Supported: ' . $this->catalog()->describeSupported() . '.');
        }
        $codes = $this->enabledCodes();
        if (!in_array($code, $codes, true)) {
            $codes[] = $code;
        }
        $this->write($codes, $this->activeCode(), $this->hotkey());

        return $layout['label'];
    }

    /**
     * Disable a layout. The pass-through fallback cannot be removed. If the
     * removed layout was active, the fallback becomes active.
     *
     * @throws \InvalidArgumentException when asked to remove the fallback layout
     */
    public function remove(string $code): void
    {
        if ($code === self::FALLBACK) {
            throw new \InvalidArgumentException('The English (pass-through) layout is the safety fallback and cannot be removed.');
        }
        $codes = array_values(array_diff($this->enabledCodes(), [$code]));
        $active = $this->activeCode() === $code ? self::FALLBACK : $this->activeCode();
        $this->write($codes, $active, $this->hotkey());
    }

    /**
     * Persist the active layout (the shell reports mouse/hotkey switches here).
     *
     * @throws \InvalidArgumentException on a layout that is not enabled
     */
    public function setActive(string $code): void
    {
        if (!in_array($code, $this->enabledCodes(), true)) {
            throw new \InvalidArgumentException('Layout not enabled: ' . $code . '.');
        }
        $this->write($this->enabledCodes(), $code, $this->hotkey());
    }

    public function setHotkey(InputHotkey $hotkey): void
    {
        $this->write($this->enabledCodes(), $this->activeCode(), $hotkey);
    }

    /** @param list<string> $codes */
    private function write(array $codes, string $active, InputHotkey $hotkey): void
    {
        $this->settings()->set(self::MODULE, self::KEY, (string) json_encode(
            ['layouts' => $codes, 'active' => $active, 'hotkey' => $hotkey->toArray()],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /** @return array<string, mixed> */
    private function raw(): array
    {
        $value = $this->settings()->get(self::MODULE, self::KEY);
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function catalog(): InputLayoutCatalog
    {
        return $this->catalog ??= new InputLayoutCatalog();
    }

    private function settings(): SettingsStoreInterface
    {
        if (!isset($this->settings)) {
            $this->settings = new SettingsStore();
        }

        return $this->settings;
    }
}
