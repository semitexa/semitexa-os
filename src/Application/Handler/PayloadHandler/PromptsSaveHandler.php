<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Os\Application\Payload\Request\PromptsSavePayload;
use Semitexa\Prompt\Application\Service\PromptOverrideStore;

/**
 * Persists a prompt override for the current tenant (save/reset) from the
 * Prompts editor, returning JSON. Tenant is resolved from request context by the
 * store, so the editor is per-tenant automatically.
 */
#[AsPayloadHandler(payload: PromptsSavePayload::class, resource: ResourceResponse::class)]
final class PromptsSaveHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected PromptOverrideStore $overrides;

    public function handle(PromptsSavePayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $id = trim($payload->getId());
        if ($id === '') {
            return $this->json($resource, ['ok' => false, 'error' => 'A prompt id is required.'], 422);
        }

        try {
            if ($payload->getAction() === 'reset') {
                $this->overrides->remove($id);

                return $this->json($resource, ['ok' => true, 'id' => $id, 'overridden' => false], 200);
            }

            $system = $payload->getSystem();
            if (trim($system) === '') {
                return $this->json($resource, ['ok' => false, 'error' => 'Override text must not be empty.'], 422);
            }

            $this->overrides->set($id, $system);

            return $this->json($resource, ['ok' => true, 'id' => $id, 'overridden' => true], 200);
        } catch (\Throwable $e) {
            return $this->json($resource, ['ok' => false, 'error' => 'Could not save: ' . $e->getMessage()], 500);
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(ResourceResponse $resource, array $body, int $status): ResourceResponse
    {
        return $resource
            ->setStatusCode($status)
            ->setContent((string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            ->setHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
