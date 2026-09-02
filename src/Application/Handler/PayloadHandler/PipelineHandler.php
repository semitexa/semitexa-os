<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsMutable;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Session\SessionInterface;
use Semitexa\Os\Application\Payload\Request\ApprovePipelinePayload;
use Semitexa\Os\Application\Service\OsSkillScope;
use Semitexa\Os\Application\Service\SkillLoopRunner;

/**
 * Executes a user-approved skill chain (orchestration) and returns the combined
 * {@see \Semitexa\Os\Domain\Model\IntentOutcome} as JSON for the Observe surface.
 */
#[AsPayloadHandler(payload: ApprovePipelinePayload::class, resource: ResourceResponse::class)]
final class PipelineHandler implements TypedHandlerInterface
{
    #[InjectAsMutable]
    protected SessionInterface $session;

    #[InjectAsReadonly]
    protected SkillLoopRunner $runner;

    #[InjectAsReadonly]
    protected OsSkillScope $scopes;

    public function handle(ApprovePipelinePayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $scope = $this->scopes->forSession(isset($this->session) ? $this->session : null);

        if ($scope === null) {
            // The route is gated, so this means the session lapsed between the
            // page load and the click. Running nothing is the only safe reading.
            return $resource
                ->setContent((string) json_encode([
                    'decision' => 'error',
                    'error' => 'Sign in again to run this.',
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
                ->setStatusCode(401)
                ->setHeader('Content-Type', 'application/json');
        }

        $outcome = $this->runner->executePipeline($payload->getIntent(), $payload->getSteps(), $scope);

        return $resource
            ->setContent((string) json_encode($outcome->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            ->setHeader('Content-Type', 'application/json');
    }
}
