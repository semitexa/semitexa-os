<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsMutable;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Csrf\CsrfToken;
use Semitexa\Core\Session\SessionInterface;
use Semitexa\Os\Application\Payload\Request\OsLoginSubmitPayload;
use Semitexa\Os\Application\Resource\Response\OsLoginResource;
use Semitexa\Os\Application\Service\OsAdminSession;
use Semitexa\Os\Application\Service\OsAuthPolicy;
use Semitexa\Platform\User\Application\Service\UserAuthenticator;
use Semitexa\Platform\User\Domain\Enum\UserRole;

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

        $attempt = $this->authenticator->attempt($email, $payload->getPassword());

        if (!$attempt->success || $attempt->user === null) {
            $message = $attempt->failure?->isSafeToDisclose() === true && $attempt->lockedUntil !== null
                ? 'Too many attempts. Try again after ' . $attempt->lockedUntil->format('H:i') . '.'
                : self::REJECTED;

            return $this->form($resource, $email, $next, $message);
        }

        // An editor of one site is still not an operator of the console. The
        // role is checked here, at the door, so no later screen has to remember.
        if ($attempt->user->getRole() === UserRole::Editor) {
            return $this->form($resource, $email, $next, self::REJECTED);
        }

        $this->admins->signIn($this->session, $attempt->user);

        $intended = $this->admins->takeIntendedPath($this->session);

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
