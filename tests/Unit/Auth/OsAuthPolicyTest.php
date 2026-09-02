<?php

declare(strict_types=1);

namespace Semitexa\Os\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Os\Application\Service\OsAuthPolicy;
use Semitexa\Os\Domain\Enum\WindowMode;

final class OsAuthPolicyTest extends TestCase
{
    #[Test]
    public function a_web_install_demands_a_sign_in_and_a_desktop_one_does_not(): void
    {
        // The default has to be the safe one: a web install sits on a shared,
        // publicly reachable server, and an un-gated shell there hands a
        // passer-by the site's own admin skills.
        self::assertTrue(self::policy(WindowMode::Web)->isRequired());
        self::assertFalse(self::policy(WindowMode::Os)->isRequired());
    }

    #[Test]
    #[DataProvider('flags')]
    public function the_env_flag_overrides_the_window_mode(string $flag, bool $expected): void
    {
        self::assertSame($expected, self::policy(WindowMode::Os, $flag)->isRequired());
    }

    /** @return iterable<string, array{string, bool}> */
    public static function flags(): iterable
    {
        yield 'on' => ['1', true];
        yield 'true' => ['true', true];
        yield 'yes' => ['YES', true];
        yield 'off' => ['0', false];
        yield 'empty means follow the window mode' => ['', false];
    }

    #[Test]
    public function an_off_switch_exists_for_a_web_install_behind_a_private_network(): void
    {
        self::assertFalse(self::policy(WindowMode::Web, '0')->isRequired());
    }

    #[Test]
    #[DataProvider('hostileReturnPaths')]
    public function a_return_path_that_could_leave_this_host_is_discarded(string $candidate): void
    {
        // ?next= arrives from the query string. An absolute or protocol-relative
        // URL there would turn the console's own login into an open redirect,
        // which is a phishing primitive with the site's name on it.
        self::assertSame('/os', self::policy(WindowMode::Web)->safeReturnPath($candidate));
    }

    /** @return iterable<string, array{string}> */
    public static function hostileReturnPaths(): iterable
    {
        yield 'absolute url' => ['https://evil.example/steal'];
        yield 'protocol relative' => ['//evil.example/steal'];
        yield 'backslash variant' => ['/\\evil.example/steal'];
        yield 'header injection' => ["/os\r\nSet-Cookie: a=b"];
        yield 'scheme only' => ['javascript:alert(1)'];
        yield 'empty' => [''];
    }

    #[Test]
    public function an_ordinary_path_on_this_host_is_kept(): void
    {
        $policy = self::policy(WindowMode::Web);

        self::assertSame('/os/app/notes', $policy->safeReturnPath('/os/app/notes'));
        self::assertSame('/admin', $policy->safeReturnPath('/admin'));
    }

    #[Test]
    public function a_shell_mounted_at_the_root_still_resolves_to_a_path(): void
    {
        self::assertSame('/', self::policy(WindowMode::Web, shellPath: '/')->shellPath());
        self::assertSame('/admin', self::policy(WindowMode::Web, shellPath: '/admin/')->shellPath());
    }

    private static function policy(
        WindowMode $mode,
        string $flag = '',
        string $shellPath = '/os',
        string $loginPath = '/os/login',
        string $logoutPath = '/os/login/out',
    ): OsAuthPolicy {
        $policy = new OsAuthPolicy();

        foreach ([
            'windowMode' => $mode,
            'authFlag' => $flag,
            'shellPath' => $shellPath,
            'loginPath' => $loginPath,
            'logoutPath' => $logoutPath,
        ] as $property => $value) {
            (new \ReflectionProperty($policy, $property))->setValue($policy, $value);
        }

        return $policy;
    }
}
