<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Os\Application\Payload\Request\PromptsHistoryPayload;
use Semitexa\Prompt\Application\Service\PromptOverrideStore;

/**
 * Returns the override version timeline for one prompt (current tenant) as JSON,
 * for the Prompts editor history panel.
 */
#[AsPayloadHandler(payload: PromptsHistoryPayload::class, resource: ResourceResponse::class)]
final class PromptsHistoryHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected PromptOverrideStore $overrides;

    public function handle(PromptsHistoryPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $id = trim($payload->getId());
        $versions = $id === '' ? [] : $this->safeHistory($id);

        return $resource
            ->setContent((string) json_encode(['id' => $id, 'versions' => $versions], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            ->setHeader('Content-Type', 'application/json; charset=utf-8');
    }

    /**
     * @return list<array{version: int, system: string, created_at: string}>
     */
    private function safeHistory(string $id): array
    {
        try {
            return $this->overrides->history($id);
        } catch (\Throwable) {
            return [];
        }
    }
}
