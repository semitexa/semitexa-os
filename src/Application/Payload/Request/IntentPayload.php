<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Contract\ValidatablePayloadInterface;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * The OS intent channel: a single natural-language intent submitted from the
 * web shell, planned and (when policy allows) executed via the Skill loop.
 *
 * v0 is single-tenant / public — Semitexa OS is a personal local-first runtime,
 * so there is no per-user gate at this layer.
 */
#[AsPublicPayload(
    path: '/os/intent',
    methods: ['POST'],
    responseWith: ResourceResponse::class,
    consumes: ['application/json', 'application/x-www-form-urlencoded'],
    produces: ['application/json'],
)]
final class IntentPayload implements ValidatablePayloadInterface
{
    private string $intent = '';

    /**
     * @return array<string, list<string>>
     */
    public function validate(): array
    {
        $errors = [];
        if (trim($this->intent) === '') {
            $errors['intent'] = ['An intent is required.'];
        }

        return $errors;
    }

    public function getIntent(): string
    {
        return $this->intent;
    }

    public function setIntent(string $intent): void
    {
        $this->intent = $intent;
    }
}
