<?php

declare(strict_types=1);

namespace Semitexa\Os\Tests\Unit\Asset;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Os\Application\Handler\PayloadHandler\OsShellHandler;

/**
 * The shell ships in semitexa/os and offers things that live elsewhere.
 *
 * semitexa/webapps is neither a dependency of this package nor part of
 * ultimate, so a normal install does not serve /os/webapps. The shell polled it
 * anyway, swallowed the 404 in an empty catch, and still told the person to ask
 * the assistant to open a site — an invitation to a capability that was not
 * there.
 */
final class ShellOffersOnlyWhatIsInstalledTest extends TestCase
{
    private const SHELL_JS = __DIR__ . '/../../../src/Application/Static/js/shell.js';

    private function shell(): string
    {
        $source = file_get_contents(self::SHELL_JS);
        self::assertIsString($source);

        return $source;
    }

    #[Test]
    public function the_boot_payload_reports_which_optional_packages_are_here(): void
    {
        $handler = (new \ReflectionClass(OsShellHandler::class))->newInstanceWithoutConstructor();
        $admins = (new \ReflectionClass(\Semitexa\Os\Application\Service\OsAdminSession::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(OsShellHandler::class, 'admins'))->setValue($handler, $admins);

        $features = (new \ReflectionMethod(OsShellHandler::class, 'features'))->invoke($handler, null);

        $this->assertArrayHasKey('webApps', $features);
        $this->assertArrayHasKey('operator', $features);
        // No session, so nobody is an operator — the shell must not open an
        // observability surface to whoever happens to load the page.
        $this->assertFalse($features['operator']);
        // This workspace HAS semitexa/webapps installed, so the probe must say
        // so — a probe that always answered false would gate the feature off
        // everywhere and look like it worked.
        $this->assertTrue($features['webApps']);
    }

    #[Test]
    public function the_probe_asks_about_a_class_that_really_exists(): void
    {
        $const = (new \ReflectionClass(OsShellHandler::class))->getConstant('WEB_APPS_PAYLOAD');

        $this->assertIsString($const);
        $this->assertTrue(
            class_exists($const),
            'the feature probe names a class nothing ships — it would report false forever',
        );
    }

    #[Test]
    public function the_template_carries_features_into_the_boot_payload(): void
    {
        // A flag the server computes and the template drops is a flag the shell
        // never sees, and the gate would silently close every feature.
        $twig = file_get_contents(__DIR__ . '/../../../src/Application/View/templates/shell.html.twig');
        $this->assertIsString($twig);
        $this->assertStringContainsString('features: features|default({})', $twig);
    }

    #[Test]
    public function every_web_apps_call_is_gated_on_the_feature(): void
    {
        $shell = $this->shell();

        $this->assertStringContainsString('const HAS_WEB_APPS = HAS.webApps === true;', $shell);

        // The fetch and both actions. Missing one leaves a dead control on an
        // install that cannot serve it.
        $this->assertSame(
            3,
            substr_count($shell, 'if (!HAS_WEB_APPS) return;'),
            'fetchWebApps, openWebApp and removeWebApp must each refuse when the package is absent',
        );
    }

    #[Test]
    public function the_empty_launcher_stops_inviting_what_cannot_be_done(): void
    {
        $shell = $this->shell();

        // The invitation to add a site survives only behind the flag.
        $this->assertMatchesRegularExpression(
            '/HAS_WEB_APPS\s*\n?\s*\?\s*t\(.no_apps_yet_ask./',
            $shell,
        );
        $this->assertStringContainsString("t('no_apps_installed'", $shell);
    }

    #[Test]
    public function the_entry_screen_stops_claiming_a_voice_it_never_hears(): void
    {
        $shell = $this->shell();

        // There is no getUserMedia, no SpeechRecognition, no audio anywhere in
        // this file, and the phase it labelled is a 1500ms setTimeout. Asserted
        // on the t() CALL rather than the prose, because the comment explaining
        // what the line used to say is worth keeping.
        // Asserted on CODE, never on prose: the comments explaining what these
        // lines used to claim legitimately quote the old wording, and an
        // assertion against English text fails on its own explanation. Three
        // times, in this file, before that stuck.
        $this->assertStringNotContainsString("t('recognising_voice'", $shell);
        $this->assertStringNotContainsString('navigator.mediaDevices', $shell);
        $this->assertStringNotContainsString('new SpeechRecognition', $shell);
        $this->assertStringContainsString("t('waking'", $shell);
        // A microphone icon over a screen that does not listen is the same claim
        // in a different medium.
        $this->assertStringNotContainsString("ico('mic', 46)", $shell);
    }

    #[Test]
    public function locality_is_claimed_only_when_the_server_says_it_is_true(): void
    {
        // "Local LLM deployment" is a claim about where a person's words GO. It
        // was printed for remote Ollama and for Gemini too.
        $shell = $this->shell();

        $this->assertStringNotContainsString("t('local_llm_deployment'", $shell);
        $this->assertStringContainsString('boot.providerLocal ?', $shell);

        $handler = new \ReflectionMethod(\Semitexa\Os\Application\Handler\PayloadHandler\OsShellHandler::class, 'isLocal');
        $this->assertTrue($handler->invoke(null, 'http://127.0.0.1:11434'));
        $this->assertTrue($handler->invoke(null, 'http://localhost:11434'));
        $this->assertFalse($handler->invoke(null, 'http://95.216.199.200:11434'), 'a remote Ollama is not local');
        $this->assertFalse($handler->invoke(null, 'https://generativelanguage.googleapis.com/v1beta'), 'Gemini is not local');
    }

    #[Test]
    public function the_first_screen_shows_no_paths_and_no_internal_identifiers(): void
    {
        $shell = $this->shell();

        // A filesystem path, shown to a museum employee.
        $this->assertStringNotContainsString('var/os/session', $shell);

        // And an internal key. Scoped to snapshotHTML — the same token seeds the
        // X-Ray trace log, which is the operator's surface and may say so.
        $snapshot = substr($shell, strpos($shell, 'function snapshotHTML()') ?: 0, 1400);
        $this->assertStringNotContainsString('os:session', $snapshot);
        $this->assertStringContainsString("t('this_device'", $snapshot);
    }

    #[Test]
    public function xray_is_the_operators_surface_and_the_panel_refuses_too(): void
    {
        $shell = $this->shell();

        $this->assertStringContainsString('const IS_OPERATOR = HAS.operator === true;', $shell);
        // A hidden toggle is not a gate.
        $this->assertStringContainsString('if (!IS_OPERATOR) return \'\';', $shell);
    }

    #[Test]
    public function the_leisure_chips_are_derived_from_what_is_installed(): void
    {
        $shell = $this->shell();

        // Four were hardcoded; three of them opened nothing without
        // semitexa/tictactoe, semitexa/music or semitexa/webapps.
        $this->assertStringContainsString("hasSkill('tic-tac-toe')", $shell);
        $this->assertStringContainsString("hasSkill('music')", $shell);
        $this->assertStringContainsString('if (HAS_WEB_APPS) offered.push', $shell);
        // Nothing installed renders nothing — not a lone "Surprise me" with
        // nothing to surprise anyone with.
        $this->assertStringContainsString('offered.length', $shell);
    }

    #[Test]
    public function both_catalogs_carry_the_new_line(): void
    {
        $dir = __DIR__ . '/../../../src/Application/View/locales/';
        foreach (['en', 'uk'] as $locale) {
            $catalog = json_decode((string) file_get_contents($dir . $locale . '.json'), true);
            foreach ([
                'shell.no_apps_installed',
                'shell.waking',
                'shell.assistant_local',
                'shell.this_device',
            ] as $key) {
                $this->assertArrayHasKey($key, $catalog, $locale . ' has no line for ' . $key);
            }
            $this->assertStringNotContainsString('var/os/session', $catalog['shell.snapshot_restored']);
        }
    }
}
