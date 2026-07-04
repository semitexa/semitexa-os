<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Os\Application\Payload\Request\WeaveAttachPayload;
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

    public function handle(WeaveAttachPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        try {
            $result = $this->graph->attachPath(
                $payload->getPath(),
                trim($payload->getKind()) === 'file' ? 'file' : 'folder',
                $payload->getConnectTo(),
            );
            $parent = $result['parent'];
            $body = [
                'ok' => true,
                'node' => $result['node']->toArray(),
                'parent' => [
                    'id' => $parent->id,
                    'title' => $parent->title,
                    'is_self' => ($parent->properties['is_self'] ?? false) === true,
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
