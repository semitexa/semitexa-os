<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Os\Application\Payload\Request\ApproveSkillPayload;
use Semitexa\Os\Application\Service\SkillLoopRunner;

/**
 * Executes a user-approved skill and returns the {@see \Semitexa\Os\Domain\Model\IntentOutcome}
 * as JSON.
 */
#[AsPayloadHandler(payload: ApproveSkillPayload::class, resource: ResourceResponse::class)]
final class ApproveSkillHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected SkillLoopRunner $runner;

    public function handle(ApproveSkillPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $outcome = $this->runner->approveAndExecute(
            $payload->getIntent(),
            $payload->getSkill(),
            $payload->getArguments(),
        );

        return $resource
            ->setContent((string) json_encode($outcome->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            ->setHeader('Content-Type', 'application/json');
    }
}
