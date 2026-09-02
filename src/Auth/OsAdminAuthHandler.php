<?php

declare(strict_types=1);

namespace Semitexa\Os\Auth;

use Semitexa\Auth\Attribute\AsAuthHandler;
use Semitexa\Auth\Domain\Contract\AuthHandlerInterface;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Auth\AuthResult;
use Semitexa\Core\Session\SessionInterface;
use Semitexa\Os\Application\Service\OsAdminSession;

/**
 * Presents the signed-in OS admin to the auth pipeline.
 *
 * Runs ahead of the generic session handler (negative priority) and answers only
 * for the console's own segment; a request with no OS session falls through
 * untouched, so a site's ordinary visitor login keeps working beside it.
 *
 * Deliberately NOT routed through UserProviderInterface: that contract belongs
 * to whichever module the host project bound it to — on a public site, plausibly
 * its customer accounts — and an admin console that accepted whoever that
 * provider returns would let a signed-in customer through its gate.
 */
#[AsAuthHandler(priority: -10)]
final class OsAdminAuthHandler implements AuthHandlerInterface
{
    #[InjectAsReadonly]
    protected OsAdminSession $admins;

    /** Called by AuthBootstrapper::resolveHandler() with the request's session. */
    protected SessionInterface $session;

    public function setSession(SessionInterface $session): void
    {
        $this->session = $session;
    }

    public function handle(object $payload): ?AuthResult
    {
        if (!isset($this->admins) || !isset($this->session)) {
            return null;
        }

        $principal = $this->admins->currentPrincipal($this->session);

        return $principal === null ? null : AuthResult::successAsUser($principal);
    }
}
