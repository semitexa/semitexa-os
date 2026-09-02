<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Payload\Request;

use Semitexa\Authorization\Attribute\AsProtectedPayload;
use Semitexa\Os\Domain\Contract\OsSurfacePayloadInterface;
use Semitexa\Core\Contract\ValidatablePayloadInterface;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * Entry route for the Calendar UI-skill — hosts the live `platform.calendar`
 * component inside the Calendar dialog surface in Focus.
 */
#[AsProtectedPayload(
    path: '/os/app/calendar',
    methods: ['GET'],
    responseWith: ResourceResponse::class,
    produces: ['text/html'],
)]
final class CalendarAppPayload implements ValidatablePayloadInterface, OsSurfacePayloadInterface
{
    /**
     * @return array<string, list<string>>
     */
    public function validate(): array
    {
        return [];
    }
}
