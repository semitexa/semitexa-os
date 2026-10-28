<?php

declare(strict_types=1);

namespace Semitexa\Os\Auth;

use Semitexa\Auth\Attribute\AsAuthHandler;
use Semitexa\Auth\Domain\Contract\AuthHandlerInterface;
use Semitexa\Authorization\Application\Service\PayloadAccessPolicyResolver;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Auth\AuthResult;
use Semitexa\Core\Auth\PayloadAccessType;
use Semitexa\Os\Application\Service\OsAuthPolicy;
use Semitexa\Os\Domain\Contract\OsSurfacePayloadInterface;

/**
 * On a desktop install with sign-in off, the person at the machine
 * ({@see OsDesktopPrincipal}) for a protected route nobody signed in to.
 *
 * Last in the chain: anyone who did sign in (a session, an API token) is
 * presented as themselves, and only a request no handler answered falls to
 * this. Every protected route, not only the console's: its Calendar reads and
 * writes /platform/calendar/events* (platform-ui), which answered 401 there.
 * A public route still sees nobody, a service route needs a machine token.
 * Only the machine's own browser: OsAuthPolicy asks a request from anywhere
 * else to sign in, so this answers it with nobody.
 */
#[AsAuthHandler(priority: 1000)]
final class OsDesktopAuthHandler implements AuthHandlerInterface
{
    #[InjectAsReadonly]
    protected OsAuthPolicy $policy;

    public function handle(object $payload): ?AuthResult
    {
        if (!isset($this->policy) || $this->policy->isRequired()) {
            return null;
        }

        return $payload instanceof OsSurfacePayloadInterface || self::isProtected($payload)
            ? AuthResult::successAsUser(new OsDesktopPrincipal())
            : null;
    }

    /** Not a route's payload (no access attribute): not protected. */
    private static function isProtected(object $payload): bool
    {
        try {
            return (new PayloadAccessPolicyResolver())->accessType($payload) === PayloadAccessType::Protected;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}
