<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Os\Application\Payload\Request\WeaveNodeRemovePayload;
use Semitexa\Weave\Domain\Contract\GraphStoreInterface;

/**
 * Remove a Weave node + its edges from the Workspace. The "self" node is
 * protected — it is the root of the graph and cannot be deleted.
 */
#[AsPayloadHandler(payload: WeaveNodeRemovePayload::class, resource: ResourceResponse::class)]
final class WeaveNodeRemoveHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected GraphStoreInterface $graph;

    public function handle(WeaveNodeRemovePayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $node = $this->graph->node($payload->getId());
        if ($node === null) {
            return $this->json($resource, ['ok' => false, 'error' => 'Unknown node.'], 404);
        }
        if (($node->getProperties()['is_self'] ?? false) === true) {
            return $this->json($resource, ['ok' => false, 'error' => 'The self node cannot be removed.'], 422);
        }

        $this->graph->removeNode($node->getId());

        return $this->json($resource, ['ok' => true], 200);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(ResourceResponse $resource, array $body, int $status): ResourceResponse
    {
        return $resource
            ->setStatusCode($status)
            ->setContent((string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            ->setHeader('Content-Type', 'application/json');
    }
}
