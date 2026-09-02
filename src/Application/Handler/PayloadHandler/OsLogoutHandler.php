<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsMutable;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Session\SessionInterface;
use Semitexa\Os\Application\Payload\Request\OsLogoutPayload;
use Semitexa\Os\Application\Resource\Response\OsLoginResource;
use Semitexa\Os\Application\Service\OsAdminSession;
use Semitexa\Os\Application\Service\OsAuthPolicy;

/**
 * Ends the console session and returns to the form.
 */
#[AsPayloadHandler(payload: OsLogoutPayload::class, resource: OsLoginResource::class)]
final class OsLogoutHandler implements TypedHandlerInterface
{
    #[InjectAsMutable]
    protected SessionInterface $session;

    #[InjectAsReadonly]
    protected OsAdminSession $admins;

    #[InjectAsReadonly]
    protected OsAuthPolicy $policy;

    public function handle(OsLogoutPayload $payload, OsLoginResource $resource): OsLoginResource
    {
        if (isset($this->session)) {
            $this->admins->signOut($this->session);
        }

        return $resource->setRedirect($this->policy->loginPath());
    }
}
