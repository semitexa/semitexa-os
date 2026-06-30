<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Os\Application\Payload\Request\SnapshotPayload;
use Semitexa\Os\Application\Service\OsSessionStore;

/**
 * Serves the OS State Snapshot (recent-session summary) as JSON for the
 * Awakening screen to restore.
 */
#[AsPayloadHandler(payload: SnapshotPayload::class, resource: ResourceResponse::class)]
final class SnapshotHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected OsSessionStore $session;

    public function handle(SnapshotPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        return $resource
            ->setContent((string) json_encode($this->session->snapshot(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            ->setHeader('Content-Type', 'application/json');
    }
}
