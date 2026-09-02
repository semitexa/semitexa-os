<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Payload\Request;

use Semitexa\Authorization\Attribute\AsProtectedPayload;
use Semitexa\Os\Domain\Contract\OsSurfacePayloadInterface;
use Semitexa\Core\Contract\ValidatablePayloadInterface;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * The OS intent channel: a single natural-language intent submitted from the
 * web shell, planned and (when policy allows) executed via the Skill loop.
 *
 * The most consequential route in the console: an intent here plans and runs
 * Skills, which is to say it edits the site. It is gated for exactly that
 * reason — see {@see \Semitexa\Os\Domain\Contract\OsSurfacePayloadInterface}.
 */
#[AsProtectedPayload(
    path: '/os/intent',
    methods: ['POST'],
    responseWith: ResourceResponse::class,
    consumes: ['application/json', 'application/x-www-form-urlencoded'],
    produces: ['application/json'],
)]
final class IntentPayload implements ValidatablePayloadInterface, OsSurfacePayloadInterface
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
