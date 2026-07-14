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

        $action = $payload->getAction();

        try {
            if ($action === 'reset') {
                $this->overrides->remove($id);

                return $this->json($resource, ['ok' => true, 'id' => $id, 'overridden' => false], 200);
            }

            if ($action === 'restore') {
                $version = $payload->getVersion();
                if (!ctype_digit($version) || !$this->overrides->revert($id, (int) $version)) {
                    return $this->json($resource, ['ok' => false, 'error' => 'Version not found.'], 404);
                }

                return $this->json($resource, ['ok' => true, 'id' => $id, 'overridden' => true, 'restored' => (int) $version], 200);
            }

            // Explicit allowlist: only an explicit "save" persists an override.
            // Payload validation already enforces this, but the handler must not
            // fall through to a write for an unexpected action if that is bypassed.
            if ($action !== 'save') {
                return $this->json($resource, ['ok' => false, 'error' => 'Unknown action.'], 422);
            }

            $system = $payload->getSystem();
            if (trim($system) === '') {
                return $this->json($resource, ['ok' => false, 'error' => 'Override text must not be empty.'], 422);
            }

            $this->overrides->set($id, $system);

            return $this->json($resource, ['ok' => true, 'id' => $id, 'overridden' => true], 200);
        } catch (\Throwable $e) {
            // Log server-side; never return the raw exception message to the client.
            error_log('PromptsSaveHandler: could not save override "' . $id . '": ' . $e->getMessage());

            return $this->json($resource, ['ok' => false, 'error' => 'Could not save the override.'], 500);
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
