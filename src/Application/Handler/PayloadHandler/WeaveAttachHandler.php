<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Os\Application\Payload\Request\WeaveAttachPayload;
use Semitexa\Os\Application\Service\ConversationStore;
use Semitexa\Os\Application\Service\OsGraph;

/**
 * Files-as-nodes seam: the Files app posts a path here and it becomes a
 * folder/file leaf node in the Weave, hung off the best-matching entity
 * (see {@see OsGraph::attachPath}). Returns where it landed so the Files
 * app can tell the user ("Added to your world, under Semitexa").
 */
#[AsPayloadHandler(payload: WeaveAttachPayload::class, resource: ResourceResponse::class)]
final class WeaveAttachHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected OsGraph $graph;

    #[InjectAsReadonly]
    protected ConversationStore $conversation;

    public function handle(WeaveAttachPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        try {
            $result = $this->graph->attachPath(
                $payload->getPath(),
                trim($payload->getKind()) === 'file' ? 'file' : 'folder',
                $payload->getConnectTo(),
            );
            $parent = $result['parent'];
            if ($payload->getNarrate()) {
                // Intent flow: make the completed attach audible in the chat —
                // the same proactive channel the weaver narrates through.
                $where = ($parent->getProperties()['is_self'] ?? false) === true ? 'you' : '"' . $parent->getTitle() . '"';
                $this->conversation->append(
                    ConversationStore::ROLE_ASSISTANT,
                    'Attached "' . $result['node']->getTitle() . '" to your world — under ' . $where . '. Open Workspace to see it.',
                    ['proactive' => true, 'source' => 'files', 'kind' => 'attached', 'skill' => 'attach-folder'],
                );
            }
            $body = [
                'ok' => true,
                'node' => $result['node']->toArray(),
                'parent' => [
                    'id' => $parent->getId(),
                    'title' => $parent->getTitle(),
                    'is_self' => ($parent->getProperties()['is_self'] ?? false) === true,
                ],
            ];
        } catch (\InvalidArgumentException $e) {
            $body = ['ok' => false, 'error' => $e->getMessage()];
        }

        return $resource
            ->setContent((string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            ->setHeader('Content-Type', 'application/json');
    }
}
