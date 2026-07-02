<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Os\Application\Payload\Request\ProactivePayload;
use Semitexa\Os\Application\Service\ConversationStore;

/**
 * Returns proactive assistant messages after the client's cursor:
 *   { cursor: <latest turn id>, items: [{ text, meta }, …] }
 *
 * With no `since`, `items` is empty and only `cursor` is returned — the shell
 * seeds its cursor without replaying history as toasts.
 */
#[AsPayloadHandler(payload: ProactivePayload::class, resource: ResourceResponse::class)]
final class ProactiveHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected ConversationStore $conversation;

    public function handle(ProactivePayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $since = $payload->getSince();
        $items = $since === '' ? [] : $this->conversation->proactiveAfter($since);

        // Advance the cursor to the newest turn id so seen messages aren't replayed.
        $cursor = $since;
        foreach ($items as $item) {
            $cursor = $item['id'];
        }
        if ($cursor === '') {
            $cursor = $this->conversation->latestId();
        }

        $body = [
            'cursor' => $cursor,
            'items' => array_map(
                static fn(array $i): array => ['text' => $i['text'], 'meta' => $i['meta']],
                $items,
            ),
        ];

        return $resource
            ->setContent((string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            ->setHeader('Content-Type', 'application/json');
    }
}
