<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Payload\Request;

use Semitexa\Authorization\Attribute\AsProtectedPayload;
use Semitexa\Os\Domain\Contract\OsSurfacePayloadInterface;
use Semitexa\Core\Contract\ValidatablePayloadInterface;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * Add a new node to the Weave from the Workspace, optionally connected to an
 * existing node (parentId → new, with the given relation).
 */
#[AsProtectedPayload(
    path: '/os/weave/node/add',
    methods: ['POST'],
    responseWith: ResourceResponse::class,
    consumes: ['application/json'],
    produces: ['application/json'],
)]
final class WeaveNodeAddPayload implements ValidatablePayloadInterface, OsSurfacePayloadInterface
{
    private string $parentId = '';

    private string $kind = '';

    private string $title = '';

    private string $description = '';

    private string $relation = '';

    /**
     * @return array<string, list<string>>
     */
    public function validate(): array
    {
        return trim($this->title) === '' ? ['title' => ['A title is required.']] : [];
    }

    public function getParentId(): string
    {
        return $this->parentId;
    }

    public function setParentId(string $parentId): void
    {
        $this->parentId = $parentId;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function setKind(string $kind): void
    {
        $this->kind = $kind;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): void
    {
        $this->description = $description;
    }

    public function getRelation(): string
    {
        return $this->relation;
    }

    public function setRelation(string $relation): void
    {
        $this->relation = $relation;
    }
}
