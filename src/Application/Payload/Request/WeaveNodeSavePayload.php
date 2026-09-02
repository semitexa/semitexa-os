<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Payload\Request;

use Semitexa\Authorization\Attribute\AsProtectedPayload;
use Semitexa\Os\Domain\Contract\OsSurfacePayloadInterface;
use Semitexa\Core\Contract\ValidatablePayloadInterface;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * Save edits to an existing Weave node from the Workspace editor: a rename
 * (`title`) and/or a merge of properties (`propsJson` — a JSON object, kept as a
 * string so the payload stays simply-typed; the handler decodes it).
 */
#[AsProtectedPayload(
    path: '/os/weave/node/save',
    methods: ['POST'],
    responseWith: ResourceResponse::class,
    consumes: ['application/json'],
    produces: ['application/json'],
)]
final class WeaveNodeSavePayload implements ValidatablePayloadInterface, OsSurfacePayloadInterface
{
    private string $id = '';

    private string $title = '';

    private string $propsJson = '';

    /**
     * @return array<string, list<string>>
     */
    public function validate(): array
    {
        return $this->id === '' ? ['id' => ['A node id is required.']] : [];
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function setId(string $id): void
    {
        $this->id = $id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function getPropsJson(): string
    {
        return $this->propsJson;
    }

    public function setPropsJson(string $propsJson): void
    {
        $this->propsJson = $propsJson;
    }
}
