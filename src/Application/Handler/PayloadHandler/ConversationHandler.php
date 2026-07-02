<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Os\Application\Payload\Request\ConversationPayload;
use Semitexa\Os\Application\Service\ConversationStore;

/**
 * Serve the saved dialog transcript as JSON.
 */
#[AsPayloadHandler(payload: ConversationPayload::class, resource: ResourceResponse::class)]
final class ConversationHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected ConversationStore $conversation;

    public function handle(ConversationPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $turns = $this->conversation->turns($payload->getLimit());

        return $resource
            ->setContent((string) json_encode(
                ['turns' => $turns, 'count' => $this->conversation->count()],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ))
            ->setHeader('Content-Type', 'application/json');
    }
}
