<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Payload\Request;

use Semitexa\Authorization\Attribute\AsProtectedPayload;
use Semitexa\Os\Domain\Contract\OsSurfacePayloadInterface;
use Semitexa\Core\Contract\ValidatablePayloadInterface;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * Poll for proactive assistant messages the OS wants to surface unprompted
 * (e.g. a background task finished). The shell polls this on a timer; `since`
 * is the last-seen turn id so each message is announced exactly once.
 */
#[AsProtectedPayload(
    path: '/os/proactive',
    methods: ['GET'],
    responseWith: ResourceResponse::class,
    produces: ['application/json'],
)]
final class ProactivePayload implements ValidatablePayloadInterface, OsSurfacePayloadInterface
{
    private string $since = '';

    /** @return array<string, list<string>> */
    public function validate(): array
    {
        return [];
    }

    public function getSince(): string { return $this->since; }
    public function setSince(string $since): void { $this->since = $since; }
}
