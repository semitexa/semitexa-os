<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Payload\Request;

use Semitexa\Authorization\Attribute\AsProtectedPayload;
use Semitexa\Os\Domain\Contract\OsSurfacePayloadInterface;
use Semitexa\Core\Contract\ValidatablePayloadInterface;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * Start a fresh conversation — clear the saved dialog transcript.
 */
#[AsProtectedPayload(
    path: '/os/conversation/clear',
    methods: ['POST'],
    responseWith: ResourceResponse::class,
    consumes: ['application/json'],
    produces: ['application/json'],
)]
final class ConversationClearPayload implements ValidatablePayloadInterface, OsSurfacePayloadInterface
{
    /**
     * @return array<string, list<string>>
     */
    public function validate(): array
    {
        return [];
    }
}
