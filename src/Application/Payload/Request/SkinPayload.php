<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * The active OS skin as a `:root{}` override, polled by the shell and injected
 * live. Empty `css` = the default (navy/cyan) look.
 */
#[AsPublicPayload(
    path: '/os/skin',
    methods: ['GET'],
    responseWith: ResourceResponse::class,
    produces: ['application/json'],
)]
final class SkinPayload
{
}
