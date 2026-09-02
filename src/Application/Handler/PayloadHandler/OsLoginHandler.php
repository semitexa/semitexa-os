<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsMutable;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Csrf\CsrfToken;
use Semitexa\Core\Session\SessionInterface;
use Semitexa\Os\Application\Payload\Request\OsLoginPayload;
use Semitexa\Os\Application\Resource\Response\OsLoginResource;
use Semitexa\Os\Application\Service\OsAdminSession;
use Semitexa\Os\Application\Service\OsAuthPolicy;
use Semitexa\Os\Application\Service\OsPreferences;

/**
 * Renders the sign-in form — or gets out of the way when there is nothing to
 * sign in to: an install that does not require auth, or a visitor who already
 * has a session, both belong in the shell rather than in front of a form.
 */
#[AsPayloadHandler(payload: OsLoginPayload::class, resource: OsLoginResource::class)]
final class OsLoginHandler implements TypedHandlerInterface
{
    #[InjectAsMutable]
    protected SessionInterface $session;

    #[InjectAsReadonly]
    protected OsAdminSession $admins;

    #[InjectAsReadonly]
    protected OsAuthPolicy $policy;

    #[InjectAsReadonly]
    protected OsPreferences $prefs;

    public function handle(OsLoginPayload $payload, OsLoginResource $resource): OsLoginResource
    {
        $next = $this->policy->safeReturnPath($payload->getNext());

        if (!$this->policy->isRequired() || $this->admins->isSignedIn($this->sessionOrNull())) {
            return $resource->setRedirect($next);
        }

        return $resource
            ->withForm(
                action: $this->policy->loginPath(),
                logoutAction: $this->policy->logoutPath(),
                csrfToken: $this->csrfToken(),
                next: $next,
            )
            ->withBranding($this->prefs->assistantName())
            ->withEmail('')
            ->withError(null)
            ->withNotice(null);
    }

    private function sessionOrNull(): ?\Semitexa\Core\Session\SessionInterface
    {
        return isset($this->session) ? $this->session : null;
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
