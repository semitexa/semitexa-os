<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Payload\Request;

use Semitexa\Authorization\Attribute\AsProtectedPayload;
use Semitexa\Os\Domain\Contract\OsOperatorSurfaceInterface;
use Semitexa\Core\Contract\ValidatablePayloadInterface;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * Runs one console-channel skill from the Terminal. The Terminal posted to
 * /os/skill, whose runner knows only the web and ui channels, so every
 * command it listed answered "Unknown skill".
 */
#[AsProtectedPayload(
    path: '/os/terminal/run',
    methods: ['POST'],
    responseWith: ResourceResponse::class,
    consumes: ['application/json'],
    produces: ['application/json'],
)]
final class TerminalRunPayload implements ValidatablePayloadInterface, OsOperatorSurfaceInterface
{
    private string $skill = '';

    /**
     * @return array<string, list<string>>
     */
    public function validate(): array
    {
        return trim($this->skill) === '' ? ['skill' => ['A skill name is required.']] : [];
    }

    public function getSkill(): string
    {
        return $this->skill;
    }

    public function setSkill(string $skill): void
    {
        $this->skill = $skill;
    }
}
