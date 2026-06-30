<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Os\Application\Payload\Request\IntentPayload;
use Semitexa\Os\Application\Service\SkillLoopRunner;

/**
 * Runs one intent through the Skill loop and returns the {@see \Semitexa\Os\Domain\Model\IntentOutcome}
 * as JSON for the Observe surface to render.
 */
#[AsPayloadHandler(payload: IntentPayload::class, resource: ResourceResponse::class)]
final class IntentHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected SkillLoopRunner $runner;

    public function handle(IntentPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $outcome = $this->runner->run($payload->getIntent());

        return $resource
            ->setContent((string) json_encode($outcome->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            ->setHeader('Content-Type', 'application/json');
    }
}
