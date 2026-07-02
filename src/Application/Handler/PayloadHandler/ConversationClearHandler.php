<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Os\Application\Payload\Request\ConversationClearPayload;
use Semitexa\Os\Application\Service\ConversationStore;

/**
 * Clear the saved dialog transcript — start a fresh conversation.
 */
#[AsPayloadHandler(payload: ConversationClearPayload::class, resource: ResourceResponse::class)]
final class ConversationClearHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected ConversationStore $conversation;

    public function handle(ConversationClearPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $this->conversation->clear();

        return $resource
            ->setContent((string) json_encode(['ok' => true], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            ->setHeader('Content-Type', 'application/json');
    }
}
