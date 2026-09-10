<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service;

use Semitexa\Ssr\Application\Service\I18n\Translator;

/**
 * What the OS says back, in the language the operator chose.
 *
 * The console shell has been translated for a while; the replies its SKILLS
 * produce were not. A Ukrainian operator could ask "перемкни на світлу тему" in
 * their own words, have it understood, and be answered "Done — switched to
 * light mode." — the one part of the exchange that is a sentence rather than a
 * label was the part still stuck in English.
 *
 * ## Why every call carries its English text
 *
 * The fallback is not defensive clutter, it is the contract. Skills run in
 * places the shell does not — a queue consumer, a CLI tick, a worker that never
 * booted the translator — and {@see Translator::trans()} answers a missing
 * catalog by handing back THE KEY. A chat reply of `os.replies.theme.dark` is
 * worse than an untranslated sentence: the shell can fall back to inline
 * English for a label, but a reply is the whole answer.
 *
 * So the English lives here as the last resort and the catalogs improve on it.
 * With no i18n at all, behaviour is byte-identical to before this existed.
 *
 * Mirrors the resolution {@see \Semitexa\Os\Application\Handler\PayloadHandler\OsShellHandler}
 * already does for `os.shell.*`, including the step that matters: a locale with
 * no catalog falls through to English rather than to the raw key.
 */
final class OsReplies
{
    private const PREFIX = 'os.replies.';

    /**
     * @param array<string, string|int|float> $params placeholders by bare name,
     *        e.g. ['title' => 'Roadmap'] for a catalog entry reading `{{title}}`
     * @param string $fallback the English sentence, in the same `{{name}}` form,
     *        used when nothing better resolves
     */
    public static function say(string $key, array $params, string $fallback): string
    {
        return self::resolve(self::PREFIX . $key, $params, $fallback);
    }

    /**
     * The same bargain for a key that is not a chat reply.
     *
     * Kept here rather than copied because the fallback semantics ARE the
     * contract — catalog(locale), then catalog(en), then the caller's English,
     * and never the raw key — and a second implementation of that is a second
     * chance to get it wrong. {@see OsSkillText} uses it for the launcher.
     *
     * @param array<string, string|int|float> $params
     */
    public static function resolve(string $full, array $params, string $fallback): string
    {
        try {
            $locale = new OsPreferences()->language();
            $service = Translator::getService();

            $text = $service->trans($full, $params, $locale);
            if ($text === $full && $locale !== 'en') {
                $text = $service->trans($full, $params, 'en');
            }

            // Still the key: no catalog anywhere ships it. The caller's English
            // is a real sentence; the key is not.
            return $text === $full ? self::interpolate($fallback, $params) : $text;
        } catch (\Throwable) {
            // No translator in this context at all (CLI tick, queue worker).
            return self::interpolate($fallback, $params);
        }
    }

    /**
     * The same `{{name}}` substitution TranslationService performs, so a
     * fallback sentence and a catalog entry are written identically and one
     * cannot drift into a different placeholder style than the other.
     *
     * @param array<string, string|int|float> $params
     */
    private static function interpolate(string $message, array $params): string
    {
        foreach ($params as $name => $value) {
            $message = str_replace('{{' . $name . '}}', (string) $value, $message);
        }

        return $message;
    }
}
