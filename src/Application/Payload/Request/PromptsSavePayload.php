<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Contract\ValidatablePayloadInterface;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * Save or reset one tenant prompt override from the Prompts editor.
 * `action` is "save" (persist {@see $system} as the override) or "reset"
 * (remove the override, falling back to the catalog).
 */
#[AsPublicPayload(
    path: '/os/prompts/save',
    methods: ['POST'],
    responseWith: ResourceResponse::class,
    consumes: ['application/json'],
    produces: ['application/json'],
)]
final class PromptsSavePayload implements ValidatablePayloadInterface
{
    private string $id = '';

    private string $action = 'save';

    private string $system = '';

    private string $version = '';

    /**
     * @return array<string, list<string>>
     */
    public function validate(): array
    {
        $errors = [];
        if ($this->id === '') {
            $errors['id'] = ['A prompt id is required.'];
        }
        if (!in_array($this->action, ['save', 'reset', 'restore'], true)) {
            $errors['action'] = ['action must be "save", "reset" or "restore".'];
        }
        if ($this->action === 'save' && trim($this->system) === '') {
            $errors['system'] = ['Override text must not be empty (use action "reset" to clear).'];
        }
        if ($this->action === 'restore' && !ctype_digit($this->version)) {
            $errors['version'] = ['A version number is required to restore.'];
        }

        return $errors;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function setVersion(string $version): void
    {
        $this->version = $version;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function setId(string $id): void
    {
        $this->id = $id;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function setAction(string $action): void
    {
        $this->action = $action;
    }

    public function getSystem(): string
    {
        return $this->system;
    }

    public function setSystem(string $system): void
    {
        $this->system = $system;
    }
}
