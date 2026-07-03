<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Platform\Settings\Application\Service\SettingsStore;
use Semitexa\Platform\Settings\Domain\Contract\SettingsStoreInterface;

/**
 * The active OS skin — a small set of themable shell variables the LLM skin
 * generator produced from a prompt. Persisted like OsPreferences (settings store,
 * module 'os'). The shell fetches {@see self::css()} via /os/skin and injects it
 * as a `:root{}` override, so the whole interface reskins live.
 *
 * Only the five tokenized shell variables are stored/emitted (see shell.css):
 * a colour-injection–safe whitelist.
 */
#[AsService]
final class SkinStore
{
    private const MODULE = 'os';
    private const KEY = 'skin';

    /** The only variables the shell is tokenized against — nothing else is emitted. */
    private const ALLOWED = ['--accent', '--accent-rgb', '--os-surface-rgb', '--os-text', '--os-bg'];

    #[InjectAsReadonly]
    protected SettingsStoreInterface $settings;

    /**
     * Persist a generated skin's DARK + LIGHT var-sets. The shell picks the block
     * matching the active `data-skin-mode`; $light may be empty for a dark-only skin.
     *
     * @param array<string, string> $dark
     * @param array<string, string> $light
     */
    public function set(array $dark, array $light = [], string $label = ''): void
    {
        $this->settings()->set(self::MODULE, self::KEY, (string) json_encode(
            ['dark' => $this->clean($dark), 'light' => $this->clean($light), 'label' => $this->sanitize($label)],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /**
     * Keep only the whitelisted, sanitised shell vars.
     *
     * @param array<string, string> $vars
     * @return array<string, string>
     */
    private function clean(array $vars): array
    {
        $clean = [];
        foreach (self::ALLOWED as $k) {
            if (isset($vars[$k]) && is_string($vars[$k])) {
                $clean[$k] = $this->sanitize($vars[$k]);
            }
        }

        return $clean;
    }

    public function clear(): void
    {
        $this->settings()->set(self::MODULE, self::KEY, '');
    }

    public function label(): string
    {
        return (string) ($this->raw()['label'] ?? '');
    }

    /**
     * The active skin's overrides: a `:root{}` (dark) block plus a
     * `:root[data-skin-mode="light"]{}` (light) block, or '' when no skin is set.
     * The shell injects this after the base tokens so it wins by source order.
     */
    public function css(): string
    {
        $raw = $this->raw();
        // Back-compat: pre-dual skins stored a single dark set under 'vars'.
        $dark = is_array($raw['dark'] ?? null) ? $raw['dark'] : (is_array($raw['vars'] ?? null) ? $raw['vars'] : []);
        $light = is_array($raw['light'] ?? null) ? $raw['light'] : [];

        return $this->block(':root', $dark) . $this->block(':root[data-skin-mode="light"]', $light);
    }

    /**
     * One `<selector>{ …vars… }` block, or '' if the set is empty.
     *
     * @param array<string, mixed> $vars
     */
    private function block(string $selector, array $vars): string
    {
        $lines = [];
        foreach (self::ALLOWED as $k) {
            if (isset($vars[$k]) && is_string($vars[$k]) && $vars[$k] !== '') {
                $lines[] = '  ' . $k . ': ' . $vars[$k] . ';';
            }
        }

        return $lines === [] ? '' : $selector . "{\n" . implode("\n", $lines) . "\n}\n";
    }

    /** @return array<string, mixed> */
    private function raw(): array
    {
        $value = $this->settings()->get(self::MODULE, self::KEY);
        if (!is_string($value) || $value === '') {
            return [];
        }
        $d = json_decode($value, true);

        return is_array($d) ? $d : [];
    }

    /** Strip anything that could break out of the `:root{ … }` declaration. */
    private function sanitize(string $v): string
    {
        $v = (string) preg_replace('/[{}<>;@]/', '', $v);

        return mb_substr(trim($v), 0, 400);
    }

    private function settings(): SettingsStoreInterface
    {
        if (!isset($this->settings)) {
            $this->settings = new SettingsStore();
        }

        return $this->settings;
    }
}
