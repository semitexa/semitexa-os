<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Llm\Application\Service\SkillRegistry;
use Semitexa\Os\Application\Payload\Request\OsShellPayload;
use Semitexa\Os\Application\Resource\Response\OsShellResource;
use Semitexa\Os\Application\Service\SkillLoopRunner;

/**
 * Renders the OS shell with boot context: the discovered skills and the LLM
 * provider's identity/health.
 */
#[AsPayloadHandler(payload: OsShellPayload::class, resource: OsShellResource::class)]
final class OsShellHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected SkillLoopRunner $runner;

    public function handle(OsShellPayload $payload, OsShellResource $resource): OsShellResource
    {
        $manifest = (new SkillRegistry())->buildManifest();

        $skills = [];
        foreach ($manifest->skills as $skill) {
            $skills[] = [
                'name' => $skill->name,
                'summary' => $skill->summary,
                'risk' => $skill->riskLevel->value,
                'icon' => $skill->icon,
                'entry' => $skill->entry,
                'is_ui' => $skill->isUi(),
            ];
        }

        $provider = $this->runner->provider();

        $healthy = false;
        try {
            $healthy = $provider->healthCheck();
        } catch (\Throwable) {
            $healthy = false;
        }

        return $resource
            ->withSkills($skills)
            ->withProvider($provider->name(), $provider->model(), $healthy);
    }
}
