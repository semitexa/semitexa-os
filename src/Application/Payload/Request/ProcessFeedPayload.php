<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Payload\Request;

use Semitexa\Authorization\Attribute\AsProtectedPayload;
use Semitexa\Os\Domain\Contract\OsSurfacePayloadInterface;
use Semitexa\Core\Attribute\SseGateModel;
use Semitexa\Core\Attribute\TransportType;
use Semitexa\Core\Attribute\WatchScopes;
use Semitexa\Core\Request;
use Semitexa\Core\Resource\JsonResourceResponse;
use Semitexa\Core\Resource\RenderProfile;
use Semitexa\Ssr\Domain\Contract\SseFeedPayloadInterface;

/**
 * `GET|POST /os/process/feed` — the held-open SSE feed of the OS process
 * registry (phase 2 of the registry: push instead of the Chill 3.5s poll).
 *
 * The watched scope is the `os_process` table: every ProcessRegistry write
 * (begin/progress/heartbeat/complete/fail — ORM insert/update) auto-publishes
 * its invalidation, and each open stream re-runs the list. A plain GET (no SSE
 * Accept) degrades to the same one-shot `{data:[...], meta}` JSON pull, so
 * the poll fallback rides the SAME url and envelope.
 *
 * No `#[LiveFilterParam]`s: the feed is the whole (tenant-scoped, bounded)
 * registry — there is nothing to filter server-side yet.
 */
#[AsProtectedPayload(
    path: '/os/process/feed',
    methods: ['GET', 'POST'],
    responseWith: JsonResourceResponse::class,
    renderProfile: RenderProfile::Json,
    transport: TransportType::Sse,
    sseGateModel: SseGateModel::BearerSession,
)]
#[WatchScopes('os_process')]
final class ProcessFeedPayload implements SseFeedPayloadInterface, OsSurfacePayloadInterface
{
    private ?string $streamId = null;

    private ?Request $httpRequest = null;

    public function setHttpRequest(Request $request): void
    {
        $this->httpRequest = $request;
    }

    public function getHttpRequest(): ?Request
    {
        return $this->httpRequest;
    }

    public function setStreamId(?string $streamId): void
    {
        $streamId = $streamId === null ? null : trim($streamId);
        $this->streamId = ($streamId === '' ? null : $streamId);
    }

    public function getStreamId(): ?string
    {
        return $this->streamId;
    }

    /** @return array<string, mixed> */
    public function toViewParams(): array
    {
        return ['streamId' => $this->streamId ?? ''];
    }
}
