<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Os\Application\Payload\Request\WeaveGraphPayload;
use Semitexa\Os\Application\Service\OsGraph;
use Semitexa\Weave\Domain\Contract\GraphStoreInterface;

/**
 * Serve the Weave graph for the Ambient Workspace (3D force-graph). Shapes the
 * store's nodes/edges into the renderer's {nodes, links} and guarantees a single
 * "self" node exists — the concept's "Me at the centre of everything", so a fresh
 * OS opens onto one node the owner can click to introduce themselves.
 */
#[AsPayloadHandler(payload: WeaveGraphPayload::class, resource: ResourceResponse::class)]
final class WeaveGraphHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected GraphStoreInterface $graph;

    #[InjectAsReadonly]
    protected OsGraph $osGraph;

    public function handle(WeaveGraphPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $data = $this->graph->graph();

        // The owner node is guaranteed by OsGraph (created on first use); make
        // sure a freshly-created one is included in this render's node list.
        $self = $this->osGraph->self();
        $selfId = $self->id;
        $present = false;
        foreach ($data['nodes'] as $node) {
            if ($node->id === $selfId) {
                $present = true;
                break;
            }
        }
        if (!$present) {
            $data['nodes'][] = $self;
        }

        $nodes = array_map(static fn ($n): array => [
            'id' => $n->id,
            'label' => $n->title,
            'kind' => $n->kind->value,
            'self' => $n->id === $selfId,
            'props' => $n->properties,
        ], $data['nodes']);

        $links = array_map(static fn ($e): array => [
            'source' => $e->fromId,
            'target' => $e->toId,
            'relation' => $e->relation,
        ], $data['edges']);

        return $resource
            ->setContent((string) json_encode(
                ['nodes' => $nodes, 'links' => $links, 'self' => $selfId],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ))
            ->setHeader('Content-Type', 'application/json');
    }
}
