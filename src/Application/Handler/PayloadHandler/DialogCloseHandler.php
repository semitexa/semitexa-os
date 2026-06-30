<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Os\Application\Payload\Request\DialogClosePayload;
use Semitexa\Os\Application\Service\OpenDialogStore;

/**
 * Closes a dialog window and any child dialogs it opened.
 */
#[AsPayloadHandler(payload: DialogClosePayload::class, resource: ResourceResponse::class)]
final class DialogCloseHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected OpenDialogStore $dialogs;

    public function handle(DialogClosePayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $this->dialogs->close($payload->getId());

        return $resource
            ->setContent((string) json_encode(['ok' => true], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            ->setHeader('Content-Type', 'application/json');
    }
}
