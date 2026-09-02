<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Payload\Request;

use Semitexa\Authorization\Attribute\AsProtectedPayload;
use Semitexa\Os\Domain\Contract\OsSurfacePayloadInterface;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * The active OS skin as a `:root{}` override, polled by the shell and injected
 * live. Empty `css` = the default (navy/cyan) look.
 */
#[AsProtectedPayload(
    path: '/os/skin',
    methods: ['GET'],
    responseWith: ResourceResponse::class,
    produces: ['application/json'],
)]
final class SkinPayload implements OsSurfacePayloadInterface
{
}
