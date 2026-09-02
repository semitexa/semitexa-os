<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Payload\Request;

use Semitexa\Authorization\Attribute\AsProtectedPayload;
use Semitexa\Os\Domain\Contract\OsSurfacePayloadInterface;
use Semitexa\Core\Contract\ValidatablePayloadInterface;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * Entry route for the Notes UI-skill — the HTML rendered inside the Notes dialog
 * surface in Focus.
 */
#[AsProtectedPayload(
    path: '/os/app/notes',
    methods: ['GET'],
    responseWith: ResourceResponse::class,
    produces: ['text/html'],
)]
final class NotesAppPayload implements ValidatablePayloadInterface, OsSurfacePayloadInterface
{
    /**
     * @return array<string, list<string>>
     */
    public function validate(): array
    {
        return [];
    }
}
