<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Os\Application\Payload\Request\ApprovePipelinePayload;
use Semitexa\Os\Application\Service\SkillLoopRunner;

/**
 * Executes a user-approved skill chain (orchestration) and returns the combined
 * {@see \Semitexa\Os\Domain\Model\IntentOutcome} as JSON for the Observe surface.
 */
#[AsPayloadHandler(payload: ApprovePipelinePayload::class, resource: ResourceResponse::class)]
final class PipelineHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected SkillLoopRunner $runner;

    public function handle(ApprovePipelinePayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $outcome = $this->runner->executePipeline($payload->getIntent(), $payload->getSteps());

        return $resource
            ->setContent((string) json_encode($outcome->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            ->setHeader('Content-Type', 'application/json');
    }
}
