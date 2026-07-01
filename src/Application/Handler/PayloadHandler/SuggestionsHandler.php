<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Os\Application\Payload\Request\SuggestionsPayload;
use Semitexa\Os\Application\Service\SuggestionEngine;

/**
 * Serves the adaptive Ambient suggestion chips — the user's own recent intents
 * blended with time-of-day / weekend context (see {@see SuggestionEngine}).
 */
#[AsPayloadHandler(payload: SuggestionsPayload::class, resource: ResourceResponse::class)]
final class SuggestionsHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected SuggestionEngine $engine;

    public function handle(SuggestionsPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $suggestions = $this->engine->suggest(
            $payload->getHour(),
            $payload->isWeekend(),
            $payload->getLimit(),
        );

        return $resource
            ->setContent((string) json_encode(['suggestions' => $suggestions], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            ->setHeader('Content-Type', 'application/json');
    }
}
