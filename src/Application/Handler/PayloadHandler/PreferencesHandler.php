<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Os\Application\Payload\Request\PreferencesPayload;
use Semitexa\Os\Application\Service\InputLayoutStore;
use Semitexa\Os\Application\Service\OsPreferences;

/**
 * Serve the OS preferences (v0: the assistant's name) as JSON. Carries the
 * input-layout state too, so a chat-skill change ("додай німецьку розкладку")
 * reaches the shell on the same preferences poll as theme/locale changes.
 */
#[AsPayloadHandler(payload: PreferencesPayload::class, resource: ResourceResponse::class)]
final class PreferencesHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected OsPreferences $prefs;

    #[InjectAsReadonly]
    protected InputLayoutStore $inputLayouts;

    public function handle(PreferencesPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $body = $this->prefs->all() + ['input_layouts' => $this->inputLayouts->state()];

        return $resource
            ->setContent((string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            ->setHeader('Content-Type', 'application/json');
    }
}
