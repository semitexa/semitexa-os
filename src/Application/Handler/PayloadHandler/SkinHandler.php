<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Os\Application\Payload\Request\SkinPayload;
use Semitexa\Os\Application\Service\SkinStore;

/** Serve the active skin: { css: "<:root override>", label: "…" } (empty css = default). */
#[AsPayloadHandler(payload: SkinPayload::class, resource: ResourceResponse::class)]
final class SkinHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected SkinStore $skin;

    public function handle(SkinPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        return $resource
            ->setContent((string) json_encode(
                ['css' => $this->skin->css(), 'label' => $this->skin->label()],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ))
            ->setHeader('Content-Type', 'application/json');
    }
}
