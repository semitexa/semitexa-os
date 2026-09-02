<?php

declare(strict_types=1);

namespace Semitexa\Os\Pipeline;

use Semitexa\Core\Attribute\AsPipelineListener;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Pipeline\AuthCheck;
use Semitexa\Core\Pipeline\Exception\AccessDeniedException;
use Semitexa\Core\Pipeline\Exception\AuthenticationRequiredException;
use Semitexa\Core\Pipeline\PipelineListenerInterface;
use Semitexa\Core\Pipeline\RequestPipelineContext;
use Semitexa\Os\Application\Service\OsAuthPolicy;
use Semitexa\Os\Domain\Contract\OsSurfacePayloadInterface;
use Semitexa\Platform\User\Auth\UserPrincipal;
use Semitexa\Platform\User\Domain\Enum\UserRole;

/**
 * The second half of the console's gate.
 *
 * `#[AsProtectedPayload]` establishes that *somebody* is signed in. On a site
 * that runs its own visitor login — a customer area, a members' page, anything
 * writing the shared `auth` segment — that somebody need not be an operator of
 * this console, and the attribute alone cannot tell the difference. So this
 * listener asks the narrower question the routes actually mean: is the
 * principal an admin of this platform?
 *
 * Runs after AuthorizationListener (priority 0), which has already resolved the
 * principal, and before CsrfListener (priority 10), so a request that has no
 * business here is turned away before anything else spends time on it.
 */
#[AsPipelineListener(phase: AuthCheck::class, priority: 5)]
final class OsAdminGate implements PipelineListenerInterface
{
    #[InjectAsReadonly]
    protected OsAuthPolicy $policy;

    public function handle(RequestPipelineContext $context): void
    {
        if (!$context->requestDto instanceof OsSurfacePayloadInterface) {
            return;
        }

        if (!isset($this->policy) || !$this->policy->isRequired()) {
            return;
        }

        $principal = $context->authResult?->user;

        if ($principal === null) {
            throw new AuthenticationRequiredException('Sign in to the console first.');
        }

        // Authenticated, but by some other identity space — a site customer, a
        // demo principal, a machine token. 403, not 401: signing in again as
        // the same person would not help, and saying so is not a hint anyone
        // can use.
        if (!$principal instanceof UserPrincipal) {
            throw new AccessDeniedException('This account is not an operator of this console.');
        }

        if (!in_array($principal->user->getRole(), [UserRole::Owner, UserRole::Admin], true)) {
            throw new AccessDeniedException('This account is not an operator of this console.');
        }
    }
}
