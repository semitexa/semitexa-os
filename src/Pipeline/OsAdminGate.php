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
use Semitexa\Core\Tenant\TenantContextStoreInterface;
use Semitexa\Os\Application\Service\OsAuthPolicy;
use Semitexa\Os\Application\Payload\Request\PasswordChangePayload;
use Semitexa\Os\Application\Payload\Request\SettingsAppPayload;
use Semitexa\Os\Domain\Contract\OsContentSurfaceInterface;
use Semitexa\Os\Domain\Contract\OsOperatorSurfaceInterface;
use Semitexa\Os\Domain\Contract\OsSurfacePayloadInterface;
use Semitexa\Platform\User\Auth\UserPrincipal;
use Semitexa\Platform\User\Domain\Enum\UserRole;
use Semitexa\Platform\User\Domain\Service\SiteAccess;

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
 *
 * An Owner opens the whole console, and alone the surfaces marked
 * {@see OsOperatorSurfaceInterface}. An Admin opens the rest. An Editor opens
 * the surfaces marked {@see OsContentSurfaceInterface} and nothing else —
 * which is what makes the role usable at all without making it a second Admin.
 */
#[AsPipelineListener(phase: AuthCheck::class, priority: 5)]
final class OsAdminGate implements PipelineListenerInterface
{
    #[InjectAsReadonly]
    protected OsAuthPolicy $policy;

    /** The site the request is for; absent without tenancy. */
    #[InjectAsReadonly(optional: true)]
    protected TenantContextStoreInterface $tenants;

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

        // A password somebody else chose is a password the account's owner has
        // not. Until they replace it, the console opens only the two surfaces
        // that let them: the settings form and the route it posts to. Checked
        // here rather than only at the login redirect, because a redirect is a
        // suggestion — the address bar is not.
        if ($principal->user->isPasswordIssuedByOperator()
            && !$context->requestDto instanceof PasswordChangePayload
            && !$context->requestDto instanceof SettingsAppPayload
        ) {
            throw new AccessDeniedException(
                'Replace the password your operator issued before using the console.',
            );
        }

        // The console's data follows the request's site, so a user bound to
        // one site is admitted on that site only: site A's admin signed in at
        // site B's host and read and wrote B's pages, chat and prompts, and on
        // the root site the operator's own.
        if (!SiteAccess::allows($principal->user, isset($this->tenants) ? $this->tenants->tryGet() : null)) {
            throw new AccessDeniedException('This account belongs to another site.');
        }

        if (self::roleOpens($principal->user->getRole(), $context->requestDto)) {
            return;
        }

        throw new AccessDeniedException($context->requestDto instanceof OsOperatorSurfaceInterface
            ? 'Only an owner of this install opens this.'
            : 'This account is not an operator of this console.');
    }

    /**
     * Whether a role opens a console surface (an instance or its class): the
     * one rule the gate, the shell's launcher and a skill that opens a window
     * ask ({@see \Semitexa\Os\Application\Service\OsConsoleAccess}).
     *
     * @param object|class-string $surface
     */
    public static function roleOpens(UserRole $role, object|string $surface): bool
    {
        // The terminal, the update journal and the queues are the install's,
        // not a site's: a site's admin is not their operator.
        if (is_a($surface, OsOperatorSurfaceInterface::class, true)) {
            return $role === UserRole::Owner;
        }

        if ($role === UserRole::Owner || $role === UserRole::Admin) {
            return true;
        }

        // An editor (the role left) has content rights, so they reach the
        // surfaces of that job and nothing else. The alternative — admitting
        // the role to everything OsSurfacePayloadInterface covers — would hand
        // a client's editor the terminal, the assistant's prompts, the process
        // registry and the update surface along with their pages.
        return is_a($surface, OsContentSurfaceInterface::class, true);
    }
}
