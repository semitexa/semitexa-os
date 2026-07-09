<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Os\Application\Payload\Request\ProcessListPayload;
use Semitexa\Os\Application\Service\ProcessRegistry;

/** Return the process registry as JSON ({processes: [...]}), newest activity first. */
#[AsPayloadHandler(payload: ProcessListPayload::class, resource: ResourceResponse::class)]
final class ProcessListHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected ProcessRegistry $registry;

    public function handle(ProcessListPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $rows = array_map(fn($p) => $this->registry->toArray($p), $this->registry->all());

        return $resource
            ->setContent((string) json_encode(
                ['processes' => $rows, 'stall_ttl' => ProcessRegistry::STALL_TTL_SECONDS],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ))
            ->setHeader('Content-Type', 'application/json');
    }
}
