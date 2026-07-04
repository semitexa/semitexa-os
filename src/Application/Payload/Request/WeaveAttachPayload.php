<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Contract\ValidatablePayloadInterface;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * Attach a real filesystem path (folder or file) to the Weave as a leaf node —
 * posted by the Files app's "Add to my world" action (files-as-nodes).
 */
#[AsPublicPayload(
    path: '/os/weave/attach',
    methods: ['POST'],
    responseWith: ResourceResponse::class,
    consumes: ['application/json'],
    produces: ['application/json'],
)]
final class WeaveAttachPayload implements ValidatablePayloadInterface
{
    private string $path = '';

    /** 'folder' (default) or 'file'. */
    private string $kind = '';

    /** Optional name of an existing entity to hang the node off. */
    private string $connectTo = '';

    /**
     * @return array<string, list<string>>
     */
    public function validate(): array
    {
        $path = trim($this->path);
        if ($path === '' || $path[0] !== '/') {
            return ['path' => ['An absolute path is required.']];
        }

        return [];
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function setPath(string $path): void
    {
        $this->path = $path;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function setKind(string $kind): void
    {
        $this->kind = $kind;
    }

    public function getConnectTo(): string
    {
        return $this->connectTo;
    }

    public function setConnectTo(string $connectTo): void
    {
        $this->connectTo = $connectTo;
    }
}
