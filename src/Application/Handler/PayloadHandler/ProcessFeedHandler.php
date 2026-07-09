<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Resource\JsonResourceResponse;
use Semitexa\Os\Application\Payload\Request\ProcessFeedPayload;
use Semitexa\Os\Application\Service\ProcessRegistry;
use Semitexa\Ssr\Application\Handler\PayloadHandler\AbstractSseFeedHandler;
use Semitexa\Ssr\Application\Service\UiEvent\UiSseEventType;
use Semitexa\Ssr\Domain\Contract\SseFeedPayloadInterface;

/**
 * Serves the process-registry feed as a live `{data:[...], meta}` collection.
 * All held-open plumbing (stream mint, re-run on `os_process` invalidation,
 * JSON degrade for plain GET) is inherited from {@see AbstractSseFeedHandler};
 * the only seam is {@see buildResponse()} — "read the registry, encode the
 * envelope". The registry's lazy stall demotion runs inside all(), so a stream
 * re-run is also what turns a silent producer's bar into 'stalled'.
 */
#[AsPayloadHandler(payload: ProcessFeedPayload::class, resource: ResourceResponse::class)]
final class ProcessFeedHandler extends AbstractSseFeedHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected ProcessRegistry $registry;

    public function handle(ProcessFeedPayload $payload, JsonResourceResponse $response): JsonResourceResponse
    {
        return $this->serve($payload, $response);
    }

    protected function buildResponse(SseFeedPayloadInterface $payload, JsonResourceResponse $response): JsonResourceResponse
    {
        if (!$payload instanceof ProcessFeedPayload) {
            throw new \LogicException('Process feed serves ProcessFeedPayload only.');
        }

        $rows = array_map(fn($p) => $this->registry->toArray($p), $this->registry->all());

        $response->setStatusCode(HttpStatus::Ok->value);
        $response->setContent(self::encode([
            'data' => $rows,
            'meta' => [
                'count' => count($rows),
                'stall_ttl' => ProcessRegistry::STALL_TTL_SECONDS,
            ],
        ]));

        return $response;
    }

    protected function successEventType(): UiSseEventType
    {
        return UiSseEventType::UiCollectionData;
    }

    protected function errorEventType(): UiSseEventType
    {
        return UiSseEventType::UiCollectionError;
    }
}
