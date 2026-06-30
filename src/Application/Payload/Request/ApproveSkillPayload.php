<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Contract\ValidatablePayloadInterface;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * The second leg of a {@see \Semitexa\Os\Domain\Enum\IntentDecision::NeedsConfirmation}
 * outcome: the user has approved a previously-proposed skill, so execute it.
 *
 * The skill name is re-validated against the live manifest by
 * {@see \Semitexa\Os\Application\Service\SkillLoopRunner::approveAndExecute()}.
 */
#[AsPublicPayload(
    path: '/os/skill',
    methods: ['POST'],
    responseWith: ResourceResponse::class,
    consumes: ['application/json'],
    produces: ['application/json'],
)]
final class ApproveSkillPayload implements ValidatablePayloadInterface
{
    private string $intent = '';
    private string $skill = '';

    /** @var array<string, scalar|null> */
    private array $arguments = [];

    /**
     * @return array<string, list<string>>
     */
    public function validate(): array
    {
        $errors = [];
        if (trim($this->skill) === '') {
            $errors['skill'] = ['A skill name is required.'];
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

    public function getSkill(): string
    {
        return $this->skill;
    }

    public function setSkill(string $skill): void
    {
        $this->skill = $skill;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function getArguments(): array
    {
        return $this->arguments;
    }

    /**
     * @param array<string, scalar|null> $arguments
     */
    public function setArguments(array $arguments): void
    {
        $this->arguments = $arguments;
    }
}
