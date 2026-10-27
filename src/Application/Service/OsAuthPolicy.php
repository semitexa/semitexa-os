<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\Config;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Os\Domain\Enum\WindowMode;

/**
 * Whether this install demands a sign-in, and where the sign-in lives.
 *
 * The default follows the window mode rather than a flag someone has to
 * remember: a web install is on a shared, publicly reachable server, so an
 * un-gated shell there hands every passer-by the site's own admin skills. An
 * `os` install owns its machine and its screen, and asking that operator to log
 * into their own desktop is ceremony.
 *
 * SEMITEXA_OS_AUTH='1' turns it on for a desktop install (one under test).
 * '0' turns it off only there: a web install always asks. It was how a web
 * install "behind a private network" opted out, but nothing here can tell a
 * private network from a public one, and with it off the console opens to
 * whoever reaches it: the terminal, the queues, every skill. A web install
 * with '0' asks anyway and logs why, once per process.
 */
#[AsService]
final class OsAuthPolicy
{
    #[Config(env: 'SEMITEXA_WINDOW_MODE', default: WindowMode::Web)]
    protected WindowMode $windowMode;

    #[Config(env: 'SEMITEXA_OS_AUTH', default: '')]
    protected string $authFlag;

    #[Config(env: 'SEMITEXA_OS_SHELL_PATH', default: '/os')]
    protected string $shellPath;

    #[Config(env: 'SEMITEXA_OS_LOGIN_PATH', default: '/os/login')]
    protected string $loginPath;

    /**
     * Its own variable rather than loginPath . '/out', because the route
     * declares it as its own env-configurable path — deriving it here would
     * silently disagree with the routing table the moment one is overridden.
     */
    #[Config(env: 'SEMITEXA_OS_LOGOUT_PATH', default: '/os/login/out')]
    protected string $logoutPath;

    /** Once per process: every request asks. */
    private static bool $warnedWebOptOut = false;

    public function isRequired(): bool
    {
        $flag = strtolower(trim($this->authFlag ?? ''));
        $mode = $this->windowMode ?? WindowMode::Web;

        if ($flag !== '' && !self::isOn($flag) && $mode === WindowMode::Web && !self::$warnedWebOptOut) {
            self::$warnedWebOptOut = true;
            StaticLoggerBridge::warning('os', "SEMITEXA_OS_AUTH='{$flag}' is ignored on a web install: the console still asks for a sign-in. Only SEMITEXA_WINDOW_MODE=os (the person's own machine) runs it without one.");
        }

        return self::requiredFor($mode, $flag);
    }

    /**
     * The rule itself, for code built without the container (a data patch):
     * a web install always asks; a desktop one when SEMITEXA_OS_AUTH is on.
     */
    public static function requiredFor(WindowMode $mode, string $flag): bool
    {
        return $mode === WindowMode::Web || self::isOn(strtolower(trim($flag)));
    }

    private static function isOn(string $flag): bool
    {
        return in_array($flag, ['1', 'true', 'yes', 'on', 'required'], true);
    }

    public function shellPath(): string
    {
        return self::normalize($this->shellPath ?? '/os', '/os');
    }

    public function loginPath(): string
    {
        return self::normalize($this->loginPath ?? '/os/login', '/os/login');
    }

    public function logoutPath(): string
    {
        return self::normalize($this->logoutPath ?? '/os/login/out', '/os/login/out');
    }

    /**
     * Where to send someone after a successful sign-in.
     *
     * Only a path on this host is honoured: `?next=` arrives from the query
     * string, and an absolute URL there would turn the console's own login into
     * an open redirect for phishing. A value that is not a plain absolute path
     * is discarded rather than repaired.
     */
    public function safeReturnPath(?string $candidate): string
    {
        $candidate = trim((string) $candidate);

        if ($candidate === '' || !str_starts_with($candidate, '/')) {
            return $this->shellPath();
        }

        // "//evil.example" and "/\evil.example" are protocol-relative URLs that
        // browsers follow off-site despite the leading slash.
        if (str_starts_with($candidate, '//') || str_starts_with($candidate, '/\\')) {
            return $this->shellPath();
        }

        if (str_contains($candidate, "\n") || str_contains($candidate, "\r")) {
            return $this->shellPath();
        }

        return $candidate;
    }

    private static function normalize(string $path, string $fallback): string
    {
        $path = trim($path);
        if ($path === '' || !str_starts_with($path, '/')) {
            return $fallback;
        }

        return rtrim($path, '/') === '' ? '/' : rtrim($path, '/');
    }
}
