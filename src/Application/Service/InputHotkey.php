<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service;

/**
 * The layout-switch key combination — parsed from loose chat phrasing
 * ("Alt+Shift", "ctrl пробіл", "Win+Space") into a canonical shape the shell's
 * keydown listener matches against.
 *
 * Two forms are valid: modifiers-only (e.g. Alt+Shift — fires when the second
 * modifier goes down while the first is held, the classic OS switcher), or
 * modifiers + one non-modifier key (e.g. Ctrl+Space). At least one modifier is
 * always required so plain typing can never trigger a switch.
 */
final class InputHotkey
{
    private const MODIFIERS = ['ctrl', 'alt', 'shift', 'meta'];

    /** Non-modifier trigger keys we accept, as KeyboardEvent.code values. */
    private const KEYS = ['Space', 'Backquote', 'CapsLock'];

    private const KEY_ALIASES = [
        'space' => 'Space', 'пробіл' => 'Space', 'probil' => 'Space', 'spacebar' => 'Space',
        'backquote' => 'Backquote', 'grave' => 'Backquote', 'tilde' => 'Backquote', '~' => 'Backquote', '`' => 'Backquote',
        'capslock' => 'CapsLock', 'caps' => 'CapsLock', 'капс' => 'CapsLock',
    ];

    private const MODIFIER_ALIASES = [
        'ctrl' => 'ctrl', 'control' => 'ctrl', 'контрол' => 'ctrl', 'ктрл' => 'ctrl',
        'alt' => 'alt', 'альт' => 'alt', 'option' => 'alt', 'opt' => 'alt',
        'shift' => 'shift', 'шифт' => 'shift', 'шіфт' => 'shift',
        'meta' => 'meta', 'super' => 'meta', 'win' => 'meta', 'windows' => 'meta', 'cmd' => 'meta', 'command' => 'meta', 'він' => 'meta',
    ];

    /**
     * @param list<string> $modifiers subset of ctrl|alt|shift|meta, canonical order
     * @param string|null $key KeyboardEvent.code of the trigger key, or null for a modifiers-only combo
     */
    private function __construct(
        public readonly array $modifiers,
        public readonly ?string $key,
    ) {
    }

    public static function default(): self
    {
        return new self(['alt', 'shift'], null);
    }

    /**
     * Parse loose phrasing into a combo, or null when nothing usable is found.
     * Accepts separators +, -, space, and Ukrainian/English key names.
     */
    public static function parse(string $raw): ?self
    {
        $tokens = preg_split('/[+\-\s,]+/u', mb_strtolower(trim($raw))) ?: [];
        $modifiers = [];
        $key = null;
        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            if (isset(self::MODIFIER_ALIASES[$token])) {
                $modifiers[self::MODIFIER_ALIASES[$token]] = true;
                continue;
            }
            if (isset(self::KEY_ALIASES[$token])) {
                $key = self::KEY_ALIASES[$token];
                continue;
            }

            return null; // an unrecognised token — better to ask than to guess
        }

        $ordered = array_values(array_intersect(self::MODIFIERS, array_keys($modifiers)));
        if ($ordered === []) {
            return null;
        }
        if ($key === null && count($ordered) < 2) {
            return null; // a single bare modifier would fire on every Shift press
        }

        return new self($ordered, $key);
    }

    /**
     * Rebuild from the stored shape ({@see self::toArray()}); falls back to the
     * default combo on anything malformed.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $modifiers = [];
        foreach (self::MODIFIERS as $m) {
            if (in_array($m, (array) ($data['modifiers'] ?? []), true)) {
                $modifiers[] = $m;
            }
        }
        $key = $data['key'] ?? null;
        $key = is_string($key) && in_array($key, self::KEYS, true) ? $key : null;
        if ($modifiers === [] || ($key === null && count($modifiers) < 2)) {
            return self::default();
        }

        return new self($modifiers, $key);
    }

    /** @return array{modifiers: list<string>, key: string|null} */
    public function toArray(): array
    {
        return ['modifiers' => $this->modifiers, 'key' => $this->key];
    }

    /** Human label, e.g. "Alt+Shift" or "Ctrl+Space". */
    public function label(): string
    {
        $names = ['ctrl' => 'Ctrl', 'alt' => 'Alt', 'shift' => 'Shift', 'meta' => 'Super'];
        $parts = array_map(static fn (string $m): string => $names[$m], $this->modifiers);
        if ($this->key !== null) {
            $parts[] = $this->key === 'Backquote' ? '`' : $this->key;
        }

        return implode('+', $parts);
    }
}
