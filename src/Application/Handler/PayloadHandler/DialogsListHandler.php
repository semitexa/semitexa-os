<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Os\Application\Payload\Request\DialogsListPayload;
use Semitexa\Os\Application\Service\OpenDialogStore;

/**
 * Serves the open-dialog list (Focus running set) as JSON.
 */
#[AsPayloadHandler(payload: DialogsListPayload::class, resource: ResourceResponse::class)]
final class DialogsListHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected OpenDialogStore $dialogs;

    public function handle(DialogsListPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        return $resource
            ->setContent((string) json_encode(['dialogs' => $this->dialogs->list()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            ->setHeader('Content-Type', 'application/json');
    }
}
