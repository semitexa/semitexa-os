<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Payload\Request;

use Semitexa\Authorization\Attribute\AsProtectedPayload;
use Semitexa\Os\Domain\Contract\OsSurfacePayloadInterface;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * Read the OS process registry as JSON — the Chill "Processes" panel polls
 * this (SSE push is the planned phase 2 of the registry).
 */
#[AsProtectedPayload(
    path: '/os/process/list',
    methods: ['GET'],
    responseWith: ResourceResponse::class,
    produces: ['application/json'],
)]
final class ProcessListPayload implements OsSurfacePayloadInterface
{
}
