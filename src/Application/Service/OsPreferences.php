<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\Config;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Platform\Settings\Application\Service\SettingsStore;
use Semitexa\Platform\Settings\Domain\Contract\SettingsStoreInterface;

/**
 * OS personalisation preferences — the assistant's name (defaults to Solomiia,
 * spelled in the console's own language) and the user's own name (empty =
 * unknown, the onboarding cue).
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
 * @phpstan-type Preferences array{assistant_name: string, user_name: string, theme_mode: string, timezone: string, locale: string, chill_apps: array<string, string>}
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
    /**
     * The assistant's name before anyone renames her, per console language.
     * The same person either way — Ukrainian writes her in Cyrillic, every
     * other language gets the transliteration the rest of the ecosystem uses.
     */
    private const DEFAULT_ASSISTANT_NAMES = ['uk' => 'Соломія'];
    private const DEFAULT_ASSISTANT_NAME = 'Solomiia';
    private const DEFAULT_TIMEZONE = 'Europe/Kyiv';

    /** Light/dark preference. 'auto' follows the OS (with a time-of-day fallback client-side). */
    private const THEME_MODES = ['auto', 'dark', 'light'];
    private const DEFAULT_THEME_MODE = 'auto';
    private const KEY_LOCALE = 'locale';

    /**
     * Remembered leisure-app choices for the Chill chips (activity => skill
     * name). First use routes through the planner; once an app opened, the
     * chip opens it directly — until the user asks to change it.
     */
    private const KEY_CHILL_APPS = 'chill_apps';
    private const CHILL_ACTIVITIES = ['music', 'video', 'game'];

    #[InjectAsReadonly]
    protected SettingsStoreInterface $settings;

    /**
     * The console's own language, when it differs from the site's.
     *
     * A public site's locale set is chosen for its visitors: an Italian theatre
     * offers it/en, and the request locale on any of its pages is one of those.
     * The people administering it are a different audience — often a different
     * language entirely — and without this the console would render in a locale
     * the OS has no catalog for, i.e. as raw message keys.
     */
    #[Config(env: 'SEMITEXA_OS_LOCALE', default: '')]
    protected string $localeOverride;

    /**
     * All preferences with defaults applied and values sanitised — safe to hand
     * straight to the shell.
     *
     * @return Preferences
     */
    public function all(): array
    {
        return [
            'assistant_name' => $this->cleanName($this->rawString(self::KEY_ASSISTANT_NAME)) ?? $this->defaultAssistantName(),
            'user_name' => $this->cleanName($this->rawString(self::KEY_USER_NAME)) ?? '',
            'theme_mode' => $this->themeMode(),
            'timezone' => $this->timezone()->getName(),
            'locale' => $this->locale(),
            'chill_apps' => $this->chillApps(),
        ];
    }

    /**
     * The remembered Chill leisure apps: activity => skill name (missing
     * activity = not chosen yet, the chip routes through the planner).
     *
     * @return array<string, string>
     */
    public function chillApps(): array
    {
        $raw = json_decode($this->rawString(self::KEY_CHILL_APPS), true);
        $apps = [];
        foreach (self::CHILL_ACTIVITIES as $activity) {
            $skill = is_array($raw) ? ($raw[$activity] ?? null) : null;
            if (is_string($skill) && $skill !== '') {
                $apps[$activity] = $skill;
            }
        }

        return $apps;
    }

    /**
     * Remember (or, with an empty $skill, forget) the app behind a Chill chip.
     *
     * @throws \InvalidArgumentException on an unknown activity
     */
    public function setChillApp(string $activity, string $skill): void
    {
        $activity = strtolower(trim($activity));
        if (!in_array($activity, self::CHILL_ACTIVITIES, true)) {
            throw new \InvalidArgumentException('Activity must be one of: ' . implode(', ', self::CHILL_ACTIVITIES) . '.');
        }
        $apps = $this->chillApps();
        $skill = trim($skill);
        if ($skill === '') {
            unset($apps[$activity]);
        } else {
            $apps[$activity] = mb_substr($skill, 0, 80);
        }
        $this->settings()->set(self::MODULE, self::KEY_CHILL_APPS, (string) json_encode($apps, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
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
     * The language the console actually speaks: the stored preference, else the
     * SEMITEXA_OS_LOCALE override, else whatever locale the request resolved to.
     *
     * This is the single answer to "what language is this console in" — the
     * shell's string bundle and the assistant's default name both read it, so
     * they cannot disagree.
     */
    public function language(): string
    {
        $locale = $this->locale();
        if ($locale === '') {
            $locale = $this->localeOverrideValue();
        }
        if ($locale === '') {
            $locale = \Semitexa\Locale\Context\LocaleContextStore::getLocale();
        }

        return self::canonicalLanguage($locale);
    }

    /**
     * The bare language subtag: 'en-US', 'en_US' and 'EN' all answer 'en'.
     *
     * Only `locale()` reads a stored preference that was checked against the
     * supported list; the env override and the ambient locale arrive in
     * whatever shape they were written, and both callers of `language()` look
     * their answer up in a map keyed by the bare subtag — the reply-language
     * instruction and the shell's string catalog. An unnormalised 'en-US'
     * missed both silently. The shape is normalised, not the value: an override
     * naming a language the install does not ship is still honoured, because
     * pinning a console to one language is what the override is for.
     */
    private static function canonicalLanguage(string $locale): string
    {
        $locale = strtolower(trim($locale));
        if ($locale === '') {
            return '';
        }

        $separator = strcspn($locale, '-_');

        return substr($locale, 0, $separator);
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

    /**
     * Her name in the console's language, used until someone renames her.
     * An unlisted language gets the Latin spelling rather than a guess.
     */
    private function defaultAssistantName(): string
    {
        $language = strtolower(substr($this->language(), 0, 2));

        return self::DEFAULT_ASSISTANT_NAMES[$language] ?? self::DEFAULT_ASSISTANT_NAME;
    }

    /**
     * #[Config] only fills the property for container-managed instances; the
     * skills that `new` this class read the same variable directly.
     */
    private function localeOverrideValue(): string
    {
        return trim(isset($this->localeOverride) && $this->localeOverride !== ''
            ? $this->localeOverride
            : (string) getenv('SEMITEXA_OS_LOCALE'));
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
