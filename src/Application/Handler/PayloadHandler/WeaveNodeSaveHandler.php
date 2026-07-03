<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Os\Application\Payload\Request\WeaveNodeSavePayload;
use Semitexa\Os\Application\Service\OsPreferences;
use Semitexa\Weave\Domain\Contract\GraphStoreInterface;

/**
 * Persist Workspace node edits — rename + merge properties. When the edited node
 * is the "self" node, the display name is also mirrored to OsPreferences so the
 * whole OS (greeting, etc.) stays coherent with what the owner typed about
 * themselves.
 */
#[AsPayloadHandler(payload: WeaveNodeSavePayload::class, resource: ResourceResponse::class)]
final class WeaveNodeSaveHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected GraphStoreInterface $graph;

    #[InjectAsReadonly]
    protected OsPreferences $prefs;

    public function handle(WeaveNodeSavePayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $props = [];
        if (trim($payload->getPropsJson()) !== '') {
            $decoded = json_decode($payload->getPropsJson(), true);
            if (is_array($decoded)) {
                $props = $decoded;
            }
        }

        $title = trim($payload->getTitle());
        $node = $this->graph->updateNode($payload->getId(), $title !== '' ? $title : null, $props);

        if ($node === null) {
            return $this->json($resource, ['ok' => false, 'error' => 'Unknown node.'], 404);
        }

        // Keep the OS's idea of the user's name in step with the self node.
        if (($node->properties['is_self'] ?? false) === true && $title !== '') {
            try {
                $this->prefs->setUserName($title);
            } catch (\InvalidArgumentException) {
                // leave the name as-is if it didn't pass sanitisation
            }
        }

        return $this->json($resource, ['ok' => true, 'node' => $node->toArray()], 200);
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
