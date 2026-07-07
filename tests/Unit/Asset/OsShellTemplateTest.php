<?php

declare(strict_types=1);

namespace Semitexa\Os\Tests\Unit\Asset;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Static pins for the OS shell delivery contract.
 *
 * The shell runtime was extracted from a 1.6k-line inline <script> in
 * shell.html.twig to a cacheable static asset (js/shell.js), and the Lucide
 * icon library moved from an unpinned public CDN to a vendored copy. These
 * pins keep both from regressing: the template stays a thin document that
 * carries only the #os-boot JSON payload and asset includes, and the desktop
 * never depends on an external host to boot.
 */
final class OsShellTemplateTest extends TestCase
{
    private const TEMPLATE_PATH =
        __DIR__ . '/../../../src/Application/View/templates/shell.html.twig';

    private const SHELL_JS_PATH =
        __DIR__ . '/../../../src/Application/Static/js/shell.js';

    private const LUCIDE_PATH =
        __DIR__ . '/../../../src/Application/Static/js/vendor/lucide.min.js';

    private static function template(): string
    {
        $source = file_get_contents(self::TEMPLATE_PATH);
        self::assertIsString($source);

        return $source;
    }

    #[Test]
    public function the_shell_runtime_is_a_static_asset_not_an_inline_script(): void
    {
        self::assertFileExists(
            self::SHELL_JS_PATH,
            'The shell runtime must live at Static/js/shell.js.',
        );

        $template = self::template();

        self::assertStringContainsString(
            "asset('js/shell.js', 'os')",
            $template,
            'shell.html.twig must include the extracted shell runtime via the fingerprinted asset() helper.',
        );

        // The only <script> without src allowed in the template is the
        // #os-boot JSON payload (type="application/json" — data, not code).
        $executableInline = preg_match_all(
            '/<script(?![^>]*\bsrc=)(?![^>]*application\/json)[^>]*>/i',
            $template,
        );
        self::assertSame(
            0,
            $executableInline,
            'shell.html.twig must not carry executable inline <script> blocks — the runtime is js/shell.js.',
        );
    }

    #[Test]
    public function the_boot_payload_contract_survives(): void
    {
        // shell.js reads the server-projected boot payload from #os-boot;
        // both sides of the contract must keep naming it.
        self::assertStringContainsString('id="os-boot"', self::template());

        $js = file_get_contents(self::SHELL_JS_PATH);
        self::assertIsString($js);
        self::assertStringContainsString(
            "getElementById('os-boot')",
            $js,
            'shell.js must boot from the #os-boot JSON payload.',
        );
    }

    #[Test]
    public function the_shell_never_loads_from_an_external_host(): void
    {
        // Covers both quote styles and protocol-relative (//host) URLs — any
        // of them would reintroduce an external-host dependency.
        self::assertDoesNotMatchRegularExpression(
            '/<(script|link)[^>]*(src|href)\s*=\s*["\'](https?:)?\/\//i',
            self::template(),
            'The OS desktop must boot with zero external hosts — vendor libraries under Static/js/vendor/.',
        );

        self::assertFileExists(
            self::LUCIDE_PATH,
            'Lucide must be vendored (it used to load unpinned from a CDN).',
        );
        self::assertStringContainsString(
            "asset('js/vendor/lucide.min.js', 'os')",
            self::template(),
            'shell.html.twig must actually load the vendored Lucide copy — a stale vendor file alone does not satisfy the contract.',
        );
    }
}
