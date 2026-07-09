<?php

declare(strict_types=1);

namespace Semitexa\Os\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Os\Application\Service\InputHotkey;

/**
 * The hotkey parser is the seam between loose chat phrasing ("ctrl пробіл",
 * "Win+Space") and the canonical combo the shell's keydown listener matches.
 * A mis-parse silently breaks layout switching, so the accepted grammar and
 * the rejections are pinned here.
 */
final class InputHotkeyTest extends TestCase
{
    /** @return array<string, array{string, list<string>, string|null}> */
    public static function parseable(): array
    {
        return [
            'classic alt+shift' => ['Alt+Shift', ['alt', 'shift'], null],
            'ctrl+space' => ['Ctrl+Space', ['ctrl'], 'Space'],
            'win+space maps to meta' => ['Win+Space', ['meta'], 'Space'],
            'super alias' => ['super space', ['meta'], 'Space'],
            'ukrainian words' => ['ктрл пробіл', ['ctrl'], 'Space'],
            'ukrainian alt shift' => ['альт+шифт', ['alt', 'shift'], null],
            'capslock with modifier' => ['shift+caps', ['shift'], 'CapsLock'],
            'backquote' => ['ctrl+`', ['ctrl'], 'Backquote'],
            'hyphen separator' => ['ctrl-shift', ['ctrl', 'shift'], null],
            'order canonicalised' => ['shift+ctrl', ['ctrl', 'shift'], null],
        ];
    }

    /** @param list<string> $modifiers */
    #[Test]
    #[DataProvider('parseable')]
    public function loose_phrasing_parses_to_a_canonical_combo(string $raw, array $modifiers, ?string $key): void
    {
        $hotkey = InputHotkey::parse($raw);

        self::assertNotNull($hotkey, $raw . ' must parse');
        self::assertSame($modifiers, $hotkey->modifiers);
        self::assertSame($key, $hotkey->key);
    }

    /** @return array<string, array{string}> */
    public static function rejected(): array
    {
        return [
            'empty' => [''],
            'a single bare modifier would fire on every press' => ['shift'],
            'bare key without any modifier' => ['space'],
            'unknown token' => ['ctrl+banana'],
            'plain letter keys are not allowed' => ['ctrl+k'],
        ];
    }

    #[Test]
    #[DataProvider('rejected')]
    public function unusable_phrasing_is_rejected_not_guessed(string $raw): void
    {
        self::assertNull(InputHotkey::parse($raw));
    }

    #[Test]
    public function stored_shape_round_trips(): void
    {
        $combo = InputHotkey::parse('Ctrl+Space');
        self::assertNotNull($combo);

        $rebuilt = InputHotkey::fromArray($combo->toArray());

        self::assertSame($combo->toArray(), $rebuilt->toArray());
    }

    #[Test]
    public function malformed_stored_data_falls_back_to_the_default_combo(): void
    {
        $rebuilt = InputHotkey::fromArray(['modifiers' => ['bogus'], 'key' => 'KeyQ']);

        self::assertSame(InputHotkey::default()->toArray(), $rebuilt->toArray());
    }

    #[Test]
    public function label_is_human_readable(): void
    {
        self::assertSame('Alt+Shift', InputHotkey::default()->label());
        self::assertSame('Ctrl+Space', InputHotkey::parse('control space')?->label());
        self::assertSame('Super+Space', InputHotkey::parse('win+space')?->label());
    }
}
