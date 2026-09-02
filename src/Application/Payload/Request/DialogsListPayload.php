<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Payload\Request;

use Semitexa\Authorization\Attribute\AsProtectedPayload;
use Semitexa\Os\Domain\Contract\OsSurfacePayloadInterface;
use Semitexa\Core\Contract\ValidatablePayloadInterface;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * List the currently-open dialog windows — the Focus zone's running set.
 */
#[AsProtectedPayload(
    path: '/os/dialogs',
    methods: ['GET'],
    responseWith: ResourceResponse::class,
    produces: ['application/json'],
)]
final class DialogsListPayload implements ValidatablePayloadInterface, OsSurfacePayloadInterface
{
    /**
     * @return array<string, list<string>>
     */
    public function validate(): array
    {
        return [];
    }
}
