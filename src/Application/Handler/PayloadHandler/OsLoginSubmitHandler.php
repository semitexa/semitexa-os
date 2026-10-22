<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsMutable;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Csrf\CsrfToken;
use Semitexa\Core\RateLimit\RateLimiter;
use Semitexa\Core\Tenant\TenantContextStoreInterface;
use Semitexa\Core\Session\SessionInterface;
use Semitexa\Os\Application\Payload\Request\OsLoginSubmitPayload;
use Semitexa\Os\Application\Resource\Response\OsLoginResource;
use Semitexa\Os\Application\Service\OsAdminSession;
use Semitexa\Os\Application\Service\OsAuthPolicy;
use Semitexa\Platform\User\Application\Service\UserAuthenticator;
use Semitexa\Platform\User\Domain\Service\SiteAccess;

/**
 * Checks one submitted pair and, if it holds up, opens the console.
 *
 * The generic CsrfListener cannot help here: it only guards requests that
 * already carry an authenticated session, and a sign-in by definition does not.
 * So the token is checked in this handler — otherwise a third-party page could
 * silently sign a victim into an account the attacker controls and watch what
 * they do with it.
 */
#[AsPayloadHandler(payload: OsLoginSubmitPayload::class, resource: OsLoginResource::class)]
final class OsLoginSubmitHandler implements TypedHandlerInterface
{
    /**
     * One sentence for every way a sign-in can fail. Telling the visitor which
     * half was wrong would let anyone test addresses against the site.
     */
    private const REJECTED = 'Wrong email or password.';

    #[InjectAsMutable]
    protected SessionInterface $session;

    #[InjectAsReadonly]
    protected OsAdminSession $admins;

    #[InjectAsReadonly]
    protected OsAuthPolicy $policy;

    #[InjectAsReadonly]
    protected UserAuthenticator $authenticator;

    /**
     * Failed sign-ins per client address: the lockout counts guesses at one
     * account, and nothing counted one client guessing across many. Mutable:
     * it reads the request it counts (execution-scoped).
     */
    #[InjectAsMutable]
    protected RateLimiter $limiter;

    /** The site the request is for; absent without tenancy. */
    #[InjectAsReadonly(optional: true)]
    protected TenantContextStoreInterface $tenants;

    /** @var list<array{int, int}> failures allowed per client address: [count, seconds] */
    private const FAILURE_LIMITS = [[10, 60], [50, 3600]];


    public function handle(OsLoginSubmitPayload $payload, OsLoginResource $resource): OsLoginResource
    {
        $next = $this->policy->safeReturnPath($payload->getNext());

        if (!$this->policy->isRequired()) {
            return $resource->setRedirect($next);
        }

        $email = trim($payload->getEmail());

        if (!$this->csrfHolds($payload->getCsrfToken())) {
            // Most often a form left open until the session rolled over, so say
            // what to do rather than accusing anyone of anything.
            return $this->form($resource, $email, $next, 'This form expired. Please try again.');
        }

        // Counted after the CSRF check, so a page elsewhere cannot burn an
        // office's budget; taken back when the sign-in succeeds, so only
        // failures count.
        $taken = [];
        foreach (isset($this->limiter) ? self::FAILURE_LIMITS : [] as [$limit, $seconds]) {
            $decision = $this->limiter->attempt('os-login-failures', $limit, $seconds);
            if (!$decision->allowed) {
                foreach ($taken as $earlier) {
                    $this->limiter->release($earlier);
                }

                return $this->form($resource, $email, $next, sprintf(
                    'Too many failed sign-ins from your network. Try again in %d minute%s.',
                    $minutes = max(1, (int) ceil($decision->retryAfter / 60)),
                    $minutes === 1 ? '' : 's',
                ));
            }
            $taken[] = $decision;
        }

        $attempt = $this->authenticator->attempt($email, $payload->getPassword());

        if ($attempt->success) {
            foreach ($taken as $decision) {
                $this->limiter->release($decision);
            }
        }

        if (!$attempt->success || $attempt->user === null) {
            $message = $attempt->failure?->isSafeToDisclose() === true && $attempt->lockedUntil !== null
                ? 'Too many attempts. Try again after ' . $attempt->lockedUntil->format('H:i') . '.'
                : self::REJECTED;

            return $this->form($resource, $email, $next, $message);
        }

        // Signed in at another site's address, the console then refused every
        // call; say so here, and start no session.
        if (!SiteAccess::allows($attempt->user, isset($this->tenants) ? $this->tenants->tryGet() : null)) {
            return $this->form($resource, $email, $next, 'This account belongs to another site. Sign in at its own address.');
        }

        $this->admins->signIn($this->session, $attempt->user);

        $intended = $this->admins->takeIntendedPath($this->session);

        // Land where the one thing they can do is: sending them to wherever they
        // were headed would show a console that refuses everything, which reads
        // as broken rather than as waiting.
        if ($attempt->user->isPasswordIssuedByOperator()) {
            return $resource->setRedirect('/os/app/settings');
        }

        return $resource->setRedirect(
            $payload->getNext() !== '' ? $next : $this->policy->safeReturnPath($intended),
        );
    }

    private function form(OsLoginResource $resource, string $email, string $next, string $error): OsLoginResource
    {
        return $resource
            ->withForm(
                action: $this->policy->loginPath(),
                logoutAction: $this->policy->logoutPath(),
                csrfToken: $this->csrfToken(),
                next: $next,
            )
            ->withEmail($email)
            ->withError($error)
            ->withNotice(null);
    }

    private function csrfHolds(string $submitted): bool
    {
        $expected = $this->csrfToken();

        return $expected !== '' && $submitted !== '' && hash_equals($expected, $submitted);
    }

    private function csrfToken(): string
    {
        if (!isset($this->session)) {
            return '';
        }

        /** @var CsrfToken $token */
        $token = $this->session->getPayload(CsrfToken::class);

        return $token->getValue();
    }
}
