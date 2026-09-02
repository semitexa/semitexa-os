<?php

declare(strict_types=1);

namespace Semitexa\Os\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Authorization\Attribute\AsProtectedPayload;
use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Os\Domain\Contract\OsSurfacePayloadInterface;

/**
 * Static pins for the shape of the gate.
 *
 * The console's routes were all public once. A new payload copied from an old
 * one inherits that, and nothing at runtime would complain: the route would
 * simply answer anonymous callers, which for /os/intent means running the
 * site's own skills for a stranger. These tests are the thing that complains.
 */
final class OsGuardedSurfaceTest extends TestCase
{
    private const PAYLOAD_DIR = __DIR__ . '/../../../src/Application/Payload/Request';

    /**
     * The door itself, and the page that points at it. Everything else in the
     * console is gated.
     */
    private const PUBLIC_BY_DESIGN = [
        'OsLoginPayload.php',
        'OsLoginSubmitPayload.php',
        'OsLogoutPayload.php',
        'OsShellPayload.php',
    ];

    #[Test]
    public function every_console_route_except_the_door_is_protected_and_marked(): void
    {
        $public = [];
        $unmarked = [];

        foreach (self::payloadFiles() as $file => $source) {
            if (in_array($file, self::PUBLIC_BY_DESIGN, true)) {
                continue;
            }

            if (!str_contains($source, '#[' . self::shortName(AsProtectedPayload::class) . '(')) {
                $public[] = $file;
            }

            if (!str_contains($source, self::shortName(OsSurfacePayloadInterface::class))) {
                $unmarked[] = $file;
            }
        }

        self::assertSame([], $public, 'These console routes still answer anonymous callers: ' . implode(', ', $public));
        self::assertSame([], $unmarked, 'These routes are protected but invisible to OsAdminGate, so a signed-in site customer passes: ' . implode(', ', $unmarked));
    }

    #[Test]
    public function the_sign_in_routes_stay_reachable_without_a_session(): void
    {
        foreach (self::PUBLIC_BY_DESIGN as $file) {
            $source = file_get_contents(self::PAYLOAD_DIR . '/' . $file);
            self::assertIsString($source);
            self::assertStringContainsString(
                '#[' . self::shortName(AsPublicPayload::class) . '(',
                $source,
                $file . ' must stay public — gating the door locks everyone out permanently.',
            );
        }
    }

    #[Test]
    public function the_shell_javascript_sends_the_csrf_token_it_now_needs(): void
    {
        // Once the routes are authenticated, CsrfListener starts enforcing on
        // every POST from the shell. Without the header the console answers 403
        // to its own UI, which looks like the feature is broken rather than
        // protected.
        $shell = file_get_contents(__DIR__ . '/../../../src/Application/Static/js/shell.js');
        self::assertIsString($shell);

        self::assertStringContainsString('XSRF-TOKEN', $shell);
        self::assertStringContainsString("headers['X-CSRF-Token']", $shell);
        self::assertStringContainsString('res.status === 401', $shell);
    }

    /** @return iterable<string, string> */
    private static function payloadFiles(): iterable
    {
        foreach (glob(self::PAYLOAD_DIR . '/*.php') ?: [] as $path) {
            $source = file_get_contents($path);
            if (is_string($source)) {
                yield basename($path) => $source;
            }
        }
    }

    private static function shortName(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);

        return end($parts);
    }
}
