<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Contract\ValidatablePayloadInterface;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * The execute leg of an orchestration outcome: the user approved a proposed
 * skill chain, so run it in order. Every step's skill is re-validated against
 * the live manifest by {@see \Semitexa\Os\Application\Service\SkillLoopRunner::executePipeline()}.
 */
#[AsPublicPayload(
    path: '/os/pipeline',
    methods: ['POST'],
    responseWith: ResourceResponse::class,
    consumes: ['application/json'],
    produces: ['application/json'],
)]
final class ApprovePipelinePayload implements ValidatablePayloadInterface
{
    private string $intent = '';

    /** @var list<array{skill: string, arguments: array<string, mixed>}> */
    private array $steps = [];

    /**
     * @return array<string, list<string>>
     */
    public function validate(): array
    {
        $errors = [];
        if ($this->steps === []) {
            $errors['steps'] = ['At least one pipeline step is required.'];
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

    /**
     * @return list<array{skill: string, arguments: array<string, mixed>}>
     */
    public function getSteps(): array
    {
        return $this->steps;
    }

    /**
     * @param array<int, array<string, mixed>> $steps
     */
    public function setSteps(array $steps): void
    {
        $clean = [];
        foreach ($steps as $step) {
            if (!is_array($step) || !isset($step['skill'])) {
                continue;
            }
            $clean[] = [
                'skill' => (string) $step['skill'],
                'arguments' => is_array($step['arguments'] ?? null) ? $step['arguments'] : [],
            ];
        }
        $this->steps = $clean;
    }
}
