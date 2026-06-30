<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Llm\Application\Service\SkillRegistry;
use Semitexa\Os\Application\Payload\Request\OpenDialogPayload;
use Semitexa\Os\Application\Service\OpenDialogStore;

/**
 * Opens a UI-skill as a dialog window: resolves its title/icon/entry from the
 * live manifest (rejecting non-UI skills), records it in {@see OpenDialogStore},
 * and returns the dialog descriptor for Focus to render.
 */
#[AsPayloadHandler(payload: OpenDialogPayload::class, resource: ResourceResponse::class)]
final class OpenDialogHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected OpenDialogStore $dialogs;

    public function handle(OpenDialogPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $skill = $payload->getSkill();
        $entry = (new SkillRegistry())->buildManifest()->findSkill($skill);

        if ($entry === null || !$entry->isUi()) {
            return $resource
                ->setStatusCode(422)
                ->setContent((string) json_encode([
                    'error' => $entry === null
                        ? "Unknown skill '{$skill}'."
                        : "Skill '{$skill}' is not a UI-skill (no dialog to open).",
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
                ->setHeader('Content-Type', 'application/json');
        }

        $dialog = $this->dialogs->open(
            skill: $entry->name,
            title: $entry->name,
            icon: $entry->icon,
            entry: $entry->entry,
            parentId: $payload->getParentId(),
        );

        return $resource
            ->setContent((string) json_encode(['dialog' => $dialog], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            ->setHeader('Content-Type', 'application/json');
    }
}
