<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Platform\Settings\Application\Service\SettingsStore;
use Semitexa\Platform\Settings\Domain\Contract\SettingsStoreInterface;

/**
 * OS personalisation preferences — the assistant's name (default "Semi") and
 * the user's own name (empty = unknown, the onboarding cue).
 *
 * Persisted in the database via the platform settings store (module `os`), not
 * a local file. Values are global (single-user OS); per-user scoping is a future
 * slice. Name-sanitisation policy (trim, cap length) lives here — the store just
 * persists the resulting strings.
 *
 * The settings store is injected for container-managed callers (handlers) and
 * lazily constructed for the skills that `new` this class outside DI
 * ({@see RenameAssistantSkill}, {@see SetUserNameSkill}).
 *
 * @phpstan-type Preferences array{assistant_name: string, user_name: string, theme_mode: string, timezone: string}
 */
#[AsService]
final class OsPreferences
{
    private const MODULE = 'os';
    private const KEY_ASSISTANT_NAME = 'assistant_name';
    private const KEY_USER_NAME = 'user_name';
    private const KEY_TIMEZONE = 'timezone';
    private const KEY_THEME_MODE = 'theme_mode';
    private const MAX_NAME_LEN = 24;
    private const DEFAULT_ASSISTANT_NAME = 'Semi';
    private const DEFAULT_TIMEZONE = 'Europe/Kyiv';

    /** Light/dark preference. 'auto' follows the OS (with a time-of-day fallback client-side). */
    private const THEME_MODES = ['auto', 'dark', 'light'];
    private const DEFAULT_THEME_MODE = 'auto';
    private const KEY_LOCALE = 'locale';

    #[InjectAsReadonly]
    protected SettingsStoreInterface $settings;

    /**
     * All preferences with defaults applied and values sanitised — safe to hand
     * straight to the shell.
     *
     * @return Preferences
     */
    public function all(): array
    {
        return [
            'assistant_name' => $this->cleanName($this->rawString(self::KEY_ASSISTANT_NAME)) ?? self::DEFAULT_ASSISTANT_NAME,
            'user_name' => $this->cleanName($this->rawString(self::KEY_USER_NAME)) ?? '',
            'theme_mode' => $this->themeMode(),
            'timezone' => $this->timezone()->getName(),
            'locale' => $this->locale(),
        ];
    }

    /**
     * The OS interface/assistant language, or '' = follow the request's own
     * locale resolution (path/header/cookie). Validated against the EFFECTIVE
     * (per-tenant) supported set when one is published for this request, else
     * the global pack — a stored locale the tenant no longer offers falls back
     * to '' rather than serving an unavailable language.
     */
    public function locale(): string
    {
        $raw = $this->rawString(self::KEY_LOCALE);
        if ($raw === '') {
            return '';
        }

        return in_array($raw, $this->supportedLocales(), true) ? $raw : '';
    }

    /**
     * Set the OS language ('' clears back to request-driven resolution).
     *
     * @return Preferences the applied preferences
     *
     * @throws \InvalidArgumentException on a locale outside the supported set
     */
    public function setLocale(string $locale): array
    {
        $locale = strtolower(trim($locale));
        if ($locale !== '' && !in_array($locale, $this->supportedLocales(), true)) {
            throw new \InvalidArgumentException(
                'Locale must be one of: ' . implode(', ', $this->supportedLocales()) . ' (or empty to follow the request).',
            );
        }
        $this->settings()->set(self::MODULE, self::KEY_LOCALE, $locale);

        return $this->all();
    }

    /** @return string[] the effective (tenant) supported set, else the global pack. */
    private function supportedLocales(): array
    {
        $supported = \Semitexa\Locale\Context\LocaleContextStore::getSupportedLocales();

        return $supported !== []
            ? $supported
            : \Semitexa\Locale\Configuration\LocaleConfig::fromEnvironment()->supportedLocales;
    }

    /** The light/dark preference — one of auto|dark|light (default auto). */
    public function themeMode(): string
    {
        $raw = $this->rawString(self::KEY_THEME_MODE);

        return in_array($raw, self::THEME_MODES, true) ? $raw : self::DEFAULT_THEME_MODE;
    }

    /**
     * Set the light/dark preference.
     *
     * @return Preferences the applied preferences
     *
     * @throws \InvalidArgumentException on an unknown mode
     */
    public function setThemeMode(string $mode): array
    {
        $mode = strtolower(trim($mode));
        if (!in_array($mode, self::THEME_MODES, true)) {
            throw new \InvalidArgumentException('Theme mode must be one of: ' . implode(', ', self::THEME_MODES) . '.');
        }
        $this->settings()->set(self::MODULE, self::KEY_THEME_MODE, $mode);

        return $this->all();
    }

    public function assistantName(): string
    {
        return $this->all()['assistant_name'];
    }

    /** The user's name, or '' when the OS does not know it yet. */
    public function userName(): string
    {
        return $this->all()['user_name'];
    }

    /**
     * The OS wall-clock timezone. The server runs in UTC, but the single user's
     * intent ("meet at 13:00") is in their local time, so time-aware skills parse
     * and display against this. Defaults to {@see self::DEFAULT_TIMEZONE}; an
     * invalid stored value falls back rather than throwing.
     */
    public function timezone(): \DateTimeZone
    {
        $raw = $this->rawString(self::KEY_TIMEZONE);

        try {
            return new \DateTimeZone($raw !== '' ? $raw : self::DEFAULT_TIMEZONE);
        } catch (\Exception) {
            return new \DateTimeZone(self::DEFAULT_TIMEZONE);
        }
    }

    /**
     * Set the OS wall-clock timezone (an IANA identifier, e.g. "Europe/Kyiv").
     * The shell auto-detects the browser's zone on boot and reconciles it here.
     *
     * @return Preferences the applied preferences
     *
     * @throws \InvalidArgumentException on an unknown identifier
     */
    public function setTimezone(string $timezone): array
    {
        $timezone = trim($timezone);

        try {
            $zone = new \DateTimeZone($timezone);
        } catch (\Exception) {
            throw new \InvalidArgumentException('Unknown timezone identifier: ' . $timezone);
        }
        $this->settings()->set(self::MODULE, self::KEY_TIMEZONE, $zone->getName());

        return $this->all();
    }

    /**
     * Rename the assistant. Returns the applied preferences (name as stored,
     * after sanitising).
     *
     * @return Preferences
     *
     * @throws \InvalidArgumentException when the name is empty after cleaning
     */
    public function setAssistantName(string $name): array
    {
        $this->store(self::KEY_ASSISTANT_NAME, $name);

        return $this->all();
    }

    /**
     * Set the user's name.
     *
     * @return Preferences the applied preferences
     *
     * @throws \InvalidArgumentException when the name is empty after cleaning
     */
    public function setUserName(string $name): array
    {
        $this->store(self::KEY_USER_NAME, $name);

        return $this->all();
    }

    private function store(string $key, string $name): void
    {
        $clean = $this->cleanName($name);
        if ($clean === null) {
            throw new \InvalidArgumentException('A name of 1–' . self::MAX_NAME_LEN . ' characters is required.');
        }

        $this->settings()->set(self::MODULE, $key, $clean);
    }

    private function rawString(string $key): string
    {
        $value = $this->settings()->get(self::MODULE, $key);

        return is_string($value) ? $value : '';
    }

    /**
     * Normalise a proposed name: strip control chars, collapse whitespace, trim,
     * cap length. Returns null when nothing usable remains.
     */
    private function cleanName(string $name): ?string
    {
        $name = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name);
        $name = trim((string) preg_replace('/\s+/', ' ', $name));
        if ($name === '') {
            return null;
        }
        if (mb_strlen($name) > self::MAX_NAME_LEN) {
            $name = mb_substr($name, 0, self::MAX_NAME_LEN);
        }

        return $name;
    }

    private function settings(): SettingsStoreInterface
    {
        return $this->settings ??= new SettingsStore();
    }
}
