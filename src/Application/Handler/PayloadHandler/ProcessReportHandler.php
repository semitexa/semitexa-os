<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Os\Application\Payload\Request\ProcessReportPayload;
use Semitexa\Os\Application\Service\ProcessRegistry;

/**
 * Accept a process report from an out-of-process producer (iframe UI-skill or
 * the native VM bridge) and apply it to the registry. Origin is normalized to
 * external/native here — 'internal' is reserved for PHP producers that call
 * ProcessRegistry directly, so a wire report can never impersonate one.
 */
#[AsPayloadHandler(payload: ProcessReportPayload::class, resource: ResourceResponse::class)]
final class ProcessReportHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected ProcessRegistry $registry;

    public function handle(ProcessReportPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $origin = $payload->getOrigin() === 'native' ? 'native' : 'external';
        $id = $payload->getId();

        $process = match ($payload->getAction()) {
            'begin' => $this->registry->begin(
                id: $id,
                source: $payload->getSource() !== '' ? $payload->getSource() : 'app',
                title: $payload->getTitle(),
                origin: $origin,
                progress: $payload->progressOrNull(),
                detail: $payload->detailOrNull(),
            ),
            'progress' => $this->registry->progress($id, $payload->progressOrNull(), $payload->detailOrNull()),
            'heartbeat' => $this->registry->heartbeat($id),
            'complete' => $this->registry->complete($id, $payload->detailOrNull()),
            'fail' => $this->registry->fail($id, $payload->detailOrNull()),
            default => null, // unreachable: validate() gates the action set
        };

        return $resource
            ->setContent((string) json_encode(
                ['ok' => $process !== null, 'process' => $process !== null ? $this->registry->toArray($process) : null],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ))
            ->setHeader('Content-Type', 'application/json');
    }
}
