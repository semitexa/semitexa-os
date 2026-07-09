<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service;

use Semitexa\Core\Attribute\AsService;

/**
 * Built-in keyboard layout definitions the OS can enable ("додай німецьку
 * розкладку"). Keymaps are keyed by physical KeyboardEvent.code so the shell
 * can remap keystrokes client-side regardless of what the host OS layout is —
 * on the VM the browser IS the desktop, so this is the only layout mechanism.
 *
 * Levels: `normal`, `shift`, `altgr`. Letters missing from `shift` uppercase
 * automatically client-side (toLocaleUpperCase), so shift levels list only
 * punctuation/symbol overrides. `en` is the pass-through layout (empty keymap
 * — keystrokes are left untouched).
 */
#[AsService]
final class InputLayoutCatalog
{
    /**
     * All known layouts, in presentation order.
     *
     * @return list<array{code: string, label: string, short: string, keymap: array{normal: array<string, string>, shift: array<string, string>, altgr: array<string, string>}}>
     */
    public function all(): array
    {
        $layouts = [];
        foreach (self::LAYOUTS as $code => $def) {
            $layouts[] = $this->payload($code, $def);
        }

        return $layouts;
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_keys(self::LAYOUTS);
    }

    public function has(string $code): bool
    {
        return isset(self::LAYOUTS[$code]);
    }

    /**
     * One layout's full payload (code, label, short badge, keymap), or null
     * when the code is not in the catalog.
     *
     * @return array{code: string, label: string, short: string, keymap: array{normal: array<string, string>, shift: array<string, string>, altgr: array<string, string>}}|null
     */
    public function get(string $code): ?array
    {
        $def = self::LAYOUTS[$code] ?? null;

        return $def === null ? null : $this->payload($code, $def);
    }

    /**
     * Map loose language phrasing ("німецька", "german", "deutsch", "de") to a
     * catalog code, or null when nothing is recognised.
     */
    public function resolve(string $raw): ?string
    {
        $v = mb_strtolower(trim($raw));
        if ($v === '') {
            return null;
        }
        if (isset(self::LAYOUTS[$v])) {
            return $v;
        }
        foreach (self::ALIASES as $code => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($v, $needle)) {
                    return $code;
                }
            }
        }

        return null;
    }

    /** Human list for "unknown language" replies: "English (en), Українська (uk), …". */
    public function describeSupported(): string
    {
        $parts = [];
        foreach (self::LAYOUTS as $code => $def) {
            $parts[] = $def['label'] . ' (' . $code . ')';
        }

        return implode(', ', $parts);
    }

    /**
     * @param array{label: string, short: string, normal?: array<string, string>, shift?: array<string, string>, altgr?: array<string, string>} $def
     * @return array{code: string, label: string, short: string, keymap: array{normal: array<string, string>, shift: array<string, string>, altgr: array<string, string>}}
     */
    private function payload(string $code, array $def): array
    {
        return [
            'code' => $code,
            'label' => $def['label'],
            'short' => $def['short'],
            'keymap' => [
                'normal' => $def['normal'] ?? [],
                'shift' => $def['shift'] ?? [],
                'altgr' => $def['altgr'] ?? [],
            ],
        ];
    }

    /** Substring needles (lowercase) mapping language names in en/uk/native to codes. */
    private const ALIASES = [
        'en' => ['англ', 'english', 'engl', 'латин', 'latin'],
        'uk' => ['укра', 'ukrain'],
        'de' => ['нім', 'deutsch', 'german', 'qwertz'],
        'fr' => ['франц', 'french', 'français', 'francais', 'azerty'],
        'es' => ['іспан', 'испан', 'spanish', 'español', 'espanol'],
        'pl' => ['поль', 'polsk', 'polish'],
    ];

    /**
     * Keymaps follow the standard national layouts (Linux xkb variants — the OS
     * VM runs X). Only keys that differ from pass-through US QWERTY are listed.
     */
    private const LAYOUTS = [
        'en' => [
            'label' => 'English',
            'short' => 'EN',
        ],
        'uk' => [
            'label' => 'Українська',
            'short' => 'UK',
            'normal' => [
                'Backquote' => "'",
                'KeyQ' => 'й', 'KeyW' => 'ц', 'KeyE' => 'у', 'KeyR' => 'к', 'KeyT' => 'е',
                'KeyY' => 'н', 'KeyU' => 'г', 'KeyI' => 'ш', 'KeyO' => 'щ', 'KeyP' => 'з',
                'BracketLeft' => 'х', 'BracketRight' => 'ї', 'Backslash' => 'ґ',
                'KeyA' => 'ф', 'KeyS' => 'і', 'KeyD' => 'в', 'KeyF' => 'а', 'KeyG' => 'п',
                'KeyH' => 'р', 'KeyJ' => 'о', 'KeyK' => 'л', 'KeyL' => 'д',
                'Semicolon' => 'ж', 'Quote' => 'є',
                'KeyZ' => 'я', 'KeyX' => 'ч', 'KeyC' => 'с', 'KeyV' => 'м', 'KeyB' => 'и',
                'KeyN' => 'т', 'KeyM' => 'ь', 'Comma' => 'б', 'Period' => 'ю', 'Slash' => '.',
            ],
            'shift' => [
                'Backquote' => '₴',
                'Digit1' => '!', 'Digit2' => '"', 'Digit3' => '№', 'Digit4' => ';', 'Digit5' => '%',
                'Digit6' => ':', 'Digit7' => '?', 'Digit8' => '*', 'Digit9' => '(', 'Digit0' => ')',
                'Slash' => ',',
            ],
        ],
        'de' => [
            'label' => 'Deutsch',
            'short' => 'DE',
            'normal' => [
                'Backquote' => '^', 'Minus' => 'ß', 'Equal' => '´',
                'KeyY' => 'z', 'KeyZ' => 'y',
                'BracketLeft' => 'ü', 'BracketRight' => '+', 'Backslash' => '#',
                'Semicolon' => 'ö', 'Quote' => 'ä', 'Slash' => '-',
            ],
            'shift' => [
                'Backquote' => '°',
                'Digit2' => '"', 'Digit3' => '§', 'Digit6' => '&', 'Digit7' => '/',
                'Digit8' => '(', 'Digit9' => ')', 'Digit0' => '=',
                'Minus' => '?', 'Equal' => '`',
                'BracketRight' => '*', 'Backslash' => "'",
                'Comma' => ';', 'Period' => ':', 'Slash' => '_',
            ],
            'altgr' => [
                'KeyQ' => '@', 'KeyE' => '€', 'KeyM' => 'µ',
                'Digit2' => '²', 'Digit3' => '³',
                'Digit7' => '{', 'Digit8' => '[', 'Digit9' => ']', 'Digit0' => '}',
                'Minus' => '\\', 'BracketRight' => '~',
            ],
        ],
        'fr' => [
            'label' => 'Français',
            'short' => 'FR',
            'normal' => [
                'Backquote' => '²',
                'Digit1' => '&', 'Digit2' => 'é', 'Digit3' => '"', 'Digit4' => "'", 'Digit5' => '(',
                'Digit6' => '-', 'Digit7' => 'è', 'Digit8' => '_', 'Digit9' => 'ç', 'Digit0' => 'à',
                'Minus' => ')',
                'KeyQ' => 'a', 'KeyW' => 'z', 'KeyA' => 'q', 'KeyZ' => 'w',
                'Semicolon' => 'm', 'KeyM' => ',',
                'BracketLeft' => '^', 'BracketRight' => '$', 'Quote' => 'ù', 'Backslash' => '*',
                'Comma' => ';', 'Period' => ':', 'Slash' => '!',
            ],
            'shift' => [
                'Digit1' => '1', 'Digit2' => '2', 'Digit3' => '3', 'Digit4' => '4', 'Digit5' => '5',
                'Digit6' => '6', 'Digit7' => '7', 'Digit8' => '8', 'Digit9' => '9', 'Digit0' => '0',
                'Minus' => '°',
                'KeyM' => '?', 'Comma' => '.', 'Period' => '/', 'Slash' => '§',
                'BracketLeft' => '¨', 'BracketRight' => '£', 'Quote' => '%', 'Backslash' => 'µ',
            ],
            'altgr' => [
                'Digit2' => '~', 'Digit3' => '#', 'Digit4' => '{', 'Digit5' => '[',
                'Digit6' => '|', 'Digit7' => '`', 'Digit8' => '\\', 'Digit9' => '^', 'Digit0' => '@',
                'Minus' => ']', 'Equal' => '}', 'KeyE' => '€',
            ],
        ],
        'es' => [
            'label' => 'Español',
            'short' => 'ES',
            'normal' => [
                'Backquote' => 'º', 'Minus' => "'", 'Equal' => '¡',
                'BracketLeft' => '`', 'BracketRight' => '+',
                'Semicolon' => 'ñ', 'Quote' => '´', 'Backslash' => 'ç', 'Slash' => '-',
            ],
            'shift' => [
                'Backquote' => 'ª',
                'Digit2' => '"', 'Digit3' => '·', 'Digit6' => '&', 'Digit7' => '/',
                'Digit8' => '(', 'Digit9' => ')', 'Digit0' => '=',
                'Minus' => '?', 'Equal' => '¿',
                'BracketLeft' => '^', 'BracketRight' => '*', 'Quote' => '¨',
                'Comma' => ';', 'Period' => ':', 'Slash' => '_',
            ],
            'altgr' => [
                'Digit1' => '|', 'Digit2' => '@', 'Digit3' => '#', 'Digit4' => '~', 'Digit6' => '¬',
                'KeyE' => '€', 'Backquote' => '\\',
                'BracketLeft' => '[', 'BracketRight' => ']', 'Quote' => '{', 'Backslash' => '}',
            ],
        ],
        'pl' => [
            'label' => 'Polski',
            'short' => 'PL',
            'altgr' => [
                'KeyA' => 'ą', 'KeyC' => 'ć', 'KeyE' => 'ę', 'KeyL' => 'ł', 'KeyN' => 'ń',
                'KeyO' => 'ó', 'KeyS' => 'ś', 'KeyX' => 'ź', 'KeyZ' => 'ż',
            ],
        ],
    ];
}
