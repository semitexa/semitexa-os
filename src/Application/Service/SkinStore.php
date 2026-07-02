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

    /** @param array<string, string> $vars */
    public function set(array $vars, string $label = ''): void
    {
        $clean = [];
        foreach (self::ALLOWED as $k) {
            if (isset($vars[$k]) && is_string($vars[$k])) {
                $clean[$k] = $this->sanitize($vars[$k]);
            }
        }
        $this->settings()->set(self::MODULE, self::KEY, (string) json_encode(
            ['vars' => $clean, 'label' => $this->sanitize($label)],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    public function clear(): void
    {
        $this->settings()->set(self::MODULE, self::KEY, '');
    }

    public function label(): string
    {
        return (string) ($this->raw()['label'] ?? '');
    }

    /** The `:root{}` override for the active skin, or '' when none (OS default). */
    public function css(): string
    {
        $vars = $this->raw()['vars'] ?? [];
        if (!is_array($vars) || $vars === []) {
            return '';
        }
        $lines = [];
        foreach (self::ALLOWED as $k) {
            if (isset($vars[$k]) && is_string($vars[$k]) && $vars[$k] !== '') {
                $lines[] = '  ' . $k . ': ' . $vars[$k] . ';';
            }
        }

        return $lines === [] ? '' : ":root{\n" . implode("\n", $lines) . "\n}";
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
