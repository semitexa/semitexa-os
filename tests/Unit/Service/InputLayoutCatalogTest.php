<?php

declare(strict_types=1);

namespace Semitexa\Os\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Os\Application\Service\InputLayoutCatalog;

/**
 * The catalog is what "додай німецьку розкладку" resolves against, and its
 * keymaps drive the shell's client-side remap engine — a malformed entry
 * types the wrong characters. Pins the alias resolution and the structural
 * contract every keymap must honour (KeyboardEvent.code keys, single-glyph
 * values, three known levels).
 */
final class InputLayoutCatalogTest extends TestCase
{
    private InputLayoutCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = new InputLayoutCatalog();
    }

    /** @return array<string, array{string, string}> */
    public static function aliases(): array
    {
        return [
            'ukrainian name in ukrainian' => ['українська', 'uk'],
            'german name in ukrainian' => ['німецька', 'de'],
            'german native' => ['Deutsch', 'de'],
            'english in ukrainian' => ['англійська', 'en'],
            'two-letter code' => ['pl', 'pl'],
            'french native' => ['français', 'fr'],
            'case-insensitive' => ['GERMAN', 'de'],
            'embedded in a phrase' => ['розкладка німецької мови', 'de'],
        ];
    }

    #[Test]
    #[DataProvider('aliases')]
    public function language_phrasing_resolves_to_a_catalog_code(string $raw, string $code): void
    {
        self::assertSame($code, $this->catalog->resolve($raw));
    }

    #[Test]
    public function unknown_language_resolves_to_null(): void
    {
        self::assertNull($this->catalog->resolve('клінгонська'));
        self::assertNull($this->catalog->resolve(''));
    }

    #[Test]
    public function every_keymap_uses_keyboard_event_codes_and_single_glyph_values(): void
    {
        // The remap engine looks up KeyboardEvent.code and inserts the value
        // verbatim — anything else silently breaks typing for that key.
        $codePattern = '/^(Key[A-Z]|Digit[0-9]|Backquote|Minus|Equal|BracketLeft|BracketRight|Backslash|Semicolon|Quote|Comma|Period|Slash|IntlBackslash)$/';
        foreach ($this->catalog->all() as $layout) {
            self::assertSame(['normal', 'shift', 'altgr'], array_keys($layout['keymap']));
            foreach ($layout['keymap'] as $level => $map) {
                foreach ($map as $code => $glyph) {
                    self::assertMatchesRegularExpression($codePattern, $code, $layout['code'] . '.' . $level . ' key ' . $code);
                    self::assertSame(1, mb_strlen($glyph), $layout['code'] . '.' . $level . '.' . $code . ' must map to exactly one character');
                }
            }
        }
    }

    #[Test]
    public function the_pass_through_layout_has_an_empty_keymap(): void
    {
        $en = $this->catalog->get('en');

        self::assertNotNull($en);
        self::assertSame([], $en['keymap']['normal']);
        self::assertSame([], $en['keymap']['shift']);
        self::assertSame([], $en['keymap']['altgr']);
    }

    #[Test]
    public function ukrainian_layout_spot_checks(): void
    {
        $uk = $this->catalog->get('uk');

        self::assertNotNull($uk);
        self::assertSame('й', $uk['keymap']['normal']['KeyQ']);
        self::assertSame('ї', $uk['keymap']['normal']['BracketRight']);
        self::assertSame('№', $uk['keymap']['shift']['Digit3']);
    }

    #[Test]
    public function supported_description_names_every_layout(): void
    {
        $described = $this->catalog->describeSupported();

        foreach ($this->catalog->codes() as $code) {
            self::assertStringContainsString('(' . $code . ')', $described);
        }
    }
}
