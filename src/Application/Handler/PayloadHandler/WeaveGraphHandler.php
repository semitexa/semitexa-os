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
        // Contextual (ego) view: centre on one node, show only its local world.
        // This is the concept's primary navigation as the graph grows — the
        // global constellation is the overview, the local view is where you work.
        $focus = trim($payload->getFocus());
        // Intent path: resolve an entity NAME into a focus server-side, so the
        // shell and the show-in-world skill share one matcher (GraphStore::search).
        if ($focus === '' && trim($payload->getFocusQuery()) !== '') {
            $hit = $this->graph->search(trim($payload->getFocusQuery()), 1)[0] ?? null;
            $focus = $hit?->id ?? '';
        }
        $focusNode = $focus !== '' ? $this->graph->node($focus) : null;
        $focus = $focusNode?->id ?? '';
        $data = $focus !== ''
            ? $this->graph->subgraph($focus, $payload->getDepth())
            : $this->graph->graph();

        $self = $this->osGraph->self();
        $selfId = $self->id;

        if ($focus === '') {
            // The owner node is guaranteed by OsGraph (created on first use); make
            // sure a freshly-created one is included in this render's node list.
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
        }

        $nodes = array_map(static fn ($n): array => [
            'id' => $n->id,
            'label' => $n->title,
            'kind' => $n->kind->value,
            'self' => $n->id === $selfId,
            // The record a node mirrors, when it mirrors one. The shell needs it
            // to open the right editor; without it a page node knows what kind
            // of thing it is and not which thing.
            'props' => $n->ref === null ? $n->properties : $n->properties + ['ref' => $n->ref],
        ], $data['nodes']);

        $links = array_map(static fn ($e): array => [
            'source' => $e->fromId,
            'target' => $e->toId,
            'relation' => $e->relation,
        ], $data['edges']);

        return $resource
            ->setContent((string) json_encode(
                [
                    'nodes' => $nodes,
                    'links' => $links,
                    'self' => $selfId,
                    'focus' => $focusNode !== null ? ['id' => $focusNode->id, 'label' => $focusNode->title] : null,
                ],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ))
            ->setHeader('Content-Type', 'application/json');
    }
}
