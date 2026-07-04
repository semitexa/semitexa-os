<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Contract\ValidatablePayloadInterface;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * Read the Weave graph (nodes + links) for the Ambient "Workspace" 3D view.
 */
#[AsPublicPayload(
    path: '/os/weave/graph',
    methods: ['GET'],
    responseWith: ResourceResponse::class,
    produces: ['application/json'],
)]
final class WeaveGraphPayload implements ValidatablePayloadInterface
{
    /** Node id to centre a CONTEXTUAL view on ('' = the whole bounded graph). */
    private string $focus = '';

    /** Hop radius for the contextual view (clamped server-side to 1..3). */
    private string $depth = '';

    /**
     * @return array<string, list<string>>
     */
    public function validate(): array
    {
        return [];
    }

    public function getFocus(): string
    {
        return $this->focus;
    }

    public function setFocus(string $focus): void
    {
        $this->focus = $focus;
    }

    public function getDepth(): int
    {
        return max(1, min(3, (int) ($this->depth !== '' ? $this->depth : 2)));
    }

    public function setDepth(string $depth): void
    {
        $this->depth = $depth;
    }
}
