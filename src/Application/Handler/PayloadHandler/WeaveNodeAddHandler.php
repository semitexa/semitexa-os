<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Os\Application\Payload\Request\WeaveNodeAddPayload;
use Semitexa\Weave\Domain\Contract\GraphStoreInterface;
use Semitexa\Weave\Domain\Enum\NodeKind;
use Semitexa\Weave\Domain\Exception\UnknownNodeException;
use Semitexa\Weave\Domain\Model\Relation;

/**
 * Create a Weave node from the Workspace and (optionally) link it to a parent —
 * the "grow the graph by hand" flow (add work → project → …). Unknown kinds
 * fall back to Topic so the graph is forgiving to fill in.
 *
 * The parent is checked before anything is written: an edge needs both ends in
 * the current tenant's graph, so a parent that is missing or another tenant's
 * would otherwise leave the new node behind with no edge. Both get the 404
 * {@see GraphStoreInterface::addEdge()} gives.
 */
#[AsPayloadHandler(payload: WeaveNodeAddPayload::class, resource: ResourceResponse::class)]
final class WeaveNodeAddHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected GraphStoreInterface $graph;

    public function handle(WeaveNodeAddPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $kind = NodeKind::tryFromLoose($payload->getKind()) ?? NodeKind::Topic;
        $description = trim($payload->getDescription());
        $props = $description !== '' ? ['description' => $description] : [];

        $parentId = trim($payload->getParentId());
        if ($parentId !== '' && $this->graph->node($parentId) === null) {
            throw new UnknownNodeException($parentId);
        }

        $node = $this->graph->upsertNode($kind, trim($payload->getTitle()), $props, 'os:workspace');

        if ($parentId !== '') {
            $relation = trim($payload->getRelation());
            $this->graph->addEdge($parentId, $node->getId(), $relation !== '' ? $relation : Relation::RELATED_TO, 100, 'os:workspace');
        }

        return $resource
            ->setContent((string) json_encode(['ok' => true, 'node' => $node->toArray()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            ->setHeader('Content-Type', 'application/json');
    }
}
