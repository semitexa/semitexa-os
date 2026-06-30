<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Os\Application\Payload\Request\DialogStatePayload;
use Semitexa\Os\Application\Service\OpenDialogStore;

/**
 * Minimize / maximize / restore a dialog window.
 */
#[AsPayloadHandler(payload: DialogStatePayload::class, resource: ResourceResponse::class)]
final class DialogStateHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected OpenDialogStore $dialogs;

    public function handle(DialogStatePayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $dialog = $this->dialogs->setState($payload->getId(), $payload->getState());

        if ($dialog === null) {
            return $resource
                ->setStatusCode(404)
                ->setContent((string) json_encode(['error' => "Dialog '{$payload->getId()}' not found."], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
                ->setHeader('Content-Type', 'application/json');
        }

        return $resource
            ->setContent((string) json_encode(['dialog' => $dialog], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            ->setHeader('Content-Type', 'application/json');
    }
}
