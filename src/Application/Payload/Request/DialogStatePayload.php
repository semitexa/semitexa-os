<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Contract\ValidatablePayloadInterface;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * Change a dialog window's state: normal | minimized | maximized.
 */
#[AsPublicPayload(
    path: '/os/dialog/state',
    methods: ['POST'],
    responseWith: ResourceResponse::class,
    consumes: ['application/json'],
    produces: ['application/json'],
)]
final class DialogStatePayload implements ValidatablePayloadInterface
{
    private string $id = '';
    private string $state = '';

    /**
     * @return array<string, list<string>>
     */
    public function validate(): array
    {
        $errors = [];
        if (trim($this->id) === '') {
            $errors['id'] = ['A dialog id is required.'];
        }
        if (!in_array($this->state, ['normal', 'minimized', 'maximized'], true)) {
            $errors['state'] = ['state must be normal, minimized or maximized.'];
        }

        return $errors;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function setId(string $id): void
    {
        $this->id = $id;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function setState(string $state): void
    {
        $this->state = $state;
    }
}
