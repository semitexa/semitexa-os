<?php

declare(strict_types=1);

namespace Semitexa\Os\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Os\Application\Service\OsReplies;
use Semitexa\Ssr\Application\Service\I18n\Translator;

/**
 * The sentences the OS says back, and the two ways they can go wrong.
 *
 * The shell has been translated for a while; the replies its SKILLS produce
 * were not — a Ukrainian operator could ask "перемкни на світлу тему" in their
 * own words and be answered "Done — switched to light mode."
 *
 * Routing them through a catalog introduces its own failure: a reply that
 * resolves to nothing renders as `os.replies.theme.dark`, which is worse than
 * the English it replaced. Skills run where the shell does not — a queue
 * consumer, a CLI tick — so that is not a hypothetical, and the English
 * fallback in each call is the contract rather than defensive clutter.
 */
final class OsRepliesTest extends TestCase
{
    private const CATALOG_DIR = __DIR__ . '/../../../src/Application/View/locales';

    protected function setUp(): void
    {
        Translator::reset();
    }

    protected function tearDown(): void
    {
        Translator::reset();
    }

    /**
     * With no translator at all — the CLI tick, the queue worker — the reply is
     * the English sentence, byte-for-byte what it was before any of this.
     */
    #[Test]
    public function a_reply_falls_back_to_its_english_when_nothing_can_translate(): void
    {
        self::assertSame(
            'Done — switched to dark mode.',
            OsReplies::say('theme.dark', [], 'Done — switched to dark mode.'),
        );
    }

    /**
     * And it must never degrade to the key. A chat reply is the whole answer,
     * not a label the surrounding UI can carry.
     */
    #[Test]
    public function an_unknown_key_never_reaches_the_operator(): void
    {
        $reply = OsReplies::say('no.such.reply', [], 'Something human.');

        self::assertSame('Something human.', $reply);
        self::assertStringNotContainsString('os.replies', $reply);
    }

    #[Test]
    public function placeholders_are_filled_on_the_fallback_path_too(): void
    {
        self::assertSame(
            'Focusing your world on "Roadmap" — opening the Workspace.',
            OsReplies::say(
                'weave.show.focusing',
                ['title' => 'Roadmap'],
                'Focusing your world on "{{title}}" — opening the Workspace.',
            ),
        );
    }

    /**
     * A key translated in one language and not the other is the drift this
     * whole feature exists to remove, reappearing in the catalogs.
     */
    #[Test]
    public function every_reply_key_is_translated_in_both_languages(): void
    {
        $en = $this->catalog('en');
        $uk = $this->catalog('uk');

        self::assertNotSame([], $en, 'the English catalog ships no replies.* keys at all');
        self::assertSame(
            array_keys($en),
            array_keys($uk),
            'the two catalogs disagree about which replies exist',
        );

        foreach ($uk as $key => $text) {
            self::assertNotSame($en[$key], $text, "{$key} is not actually translated");
        }
    }

    /**
     * The one that keeps this honest. Each call site carries an English
     * sentence AND the catalog carries one; nothing but a test stops the two
     * from drifting, and a drifted pair is invisible — both render fine, in
     * different words, depending on whether the translator happened to boot.
     */
    #[Test]
    public function each_call_sites_english_matches_the_catalog_entry(): void
    {
        $en = $this->catalog('en');
        $checked = 0;

        foreach ($this->callSites() as $key => $fallback) {
            self::assertArrayHasKey(
                'replies.' . $key,
                $en,
                "OsReplies::say('{$key}', ...) has no entry in the English catalog",
            );
            self::assertSame(
                $en['replies.' . $key],
                $fallback,
                "the English written at the call site for '{$key}' has drifted from the catalog",
            );
            $checked++;
        }

        self::assertGreaterThanOrEqual(
            12,
            $checked,
            'the call-site scan found almost nothing — it is no longer reading the sources',
        );
    }

    /** @return array<string, string> os.replies.* entries of one locale */
    private function catalog(string $locale): array
    {
        /** @var array<string, string> $all */
        $all = json_decode((string) file_get_contents(self::CATALOG_DIR . '/' . $locale . '.json'), true);

        // Stored WITHOUT the module prefix, like every other key in these
        // files. TranslationCatalog::addMessages() registers each entry as
        // `<module>.<key>`, so `replies.theme.dark` in the file is what the OS
        // looks up as `os.replies.theme.dark`. Writing the prefix into the file
        // would have produced `os.os.replies.*` and resolved only through the
        // unnamespaced alias — which works until another module claims the same
        // bare key first.
        return array_filter(
            $all,
            static fn (string $key): bool => str_starts_with($key, 'replies.'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Every OsReplies::say() in the repository, as key => the English written
     * beside it.
     *
     * Read with PHP's own tokenizer, not a regular expression. A pattern that
     * parses PHP is a pattern that quietly stops matching — the first draft of
     * this found eleven of the thirteen call sites and reported success on the
     * eleven, which is precisely the blindness this test exists to catch in
     * other people's code.
     *
     * @return array<string, string>
     */
    private function callSites(): array
    {
        $root = dirname(__DIR__, 4);
        $found = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = str_replace($root . '/', '', $file->getPathname());
            if (!preg_match('#^[a-z0-9-]+/src/#', $path)) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            if (!str_contains($source, 'OsReplies::say(')) {
                continue;
            }

            foreach ($this->saysIn($source) as $key => $fallback) {
                $found[$key] = $fallback;
            }
        }

        return $found;
    }

    /**
     * @return array<string, string> key => third argument, for each say() call
     */
    private function saysIn(string $source): array
    {
        $tokens = array_values(array_filter(
            token_get_all($source),
            static fn (array|string $t): bool => !is_array($t)
                || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));

        $out = [];

        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_STRING || $tokens[$i][1] !== 'say') {
                continue;
            }
            if (($tokens[$i + 1] ?? null) !== '(') {
                continue;
            }

            // Walk the argument list at depth 1, collecting the first and third
            // top-level arguments. Nesting is counted rather than pattern-matched,
            // so an array or a call inside an argument cannot end it early.
            $depth = 0;
            $arg = 0;
            $first = null;
            $third = null;

            for ($j = $i + 1; $j < $n; $j++) {
                $t = $tokens[$j];

                if ($t === '(' || $t === '[') {
                    $depth++;
                    continue;
                }
                if ($t === ')' || $t === ']') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                    continue;
                }
                if ($t === ',' && $depth === 1) {
                    $arg++;
                    continue;
                }
                if ($depth === 1 && is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
                    if ($arg === 0 && $first === null) {
                        $first = $t[1];
                    } elseif ($arg === 2 && $third === null) {
                        $third = $t[1];
                    }
                }
            }

            if ($first !== null && $third !== null) {
                $out[self::literal($first)] = self::literal($third);
            }
        }

        return $out;
    }

    /** The value of a single- or double-quoted PHP literal. */
    private static function literal(string $token): string
    {
        $body = substr($token, 1, -1);

        return $token[0] === '"'
            ? stripcslashes($body)
            : str_replace(["\\'", '\\\\'], ["'", '\\'], $body);
    }
}
