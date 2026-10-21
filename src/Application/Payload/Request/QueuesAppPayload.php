<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Payload\Request;

use Semitexa\Authorization\Attribute\AsProtectedPayload;
use Semitexa\Os\Domain\Contract\OsSurfacePayloadInterface;
use Semitexa\Core\Contract\ValidatablePayloadInterface;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * Entry route for the Queues UI-skill: per-queue throughput, failures,
 * wait and run time, what is waiting and what was dead-lettered.
 */
#[AsProtectedPayload(
    path: '/os/app/queues',
    methods: ['GET'],
    responseWith: ResourceResponse::class,
    produces: ['text/html'],
)]
final class QueuesAppPayload implements ValidatablePayloadInterface, OsSurfacePayloadInterface
{
    /**
     * @return array<string, list<string>>
     */
    public function validate(): array
    {
        return [];
    }
}
