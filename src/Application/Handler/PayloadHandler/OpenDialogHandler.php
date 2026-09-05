<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsMutable;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Session\SessionInterface;
use Semitexa\Llm\Application\Service\TenantSkillScope;
use Semitexa\Os\Application\Payload\Request\OpenDialogPayload;
use Semitexa\Os\Application\Service\OpenDialogStore;
use Semitexa\Os\Application\Service\OsSkillScope;
use Semitexa\Weave\Domain\Contract\GraphStoreInterface;

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

    #[InjectAsMutable]
    protected SessionInterface $session;

    #[InjectAsReadonly]
    protected GraphStoreInterface $graph;

    #[InjectAsReadonly]
    protected OsSkillScope $skillScope;

    #[InjectAsReadonly]
    protected TenantSkillScope $skills;

    public function handle(OpenDialogPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $skill = $payload->getSkill();
        $scope = $this->skillScope->forSession(isset($this->session) ? $this->session : null);
        $manifest = $scope === null
            ? new \Semitexa\Llm\Domain\Model\SkillManifest('semitexa.ai-skills/v1', gmdate('c'), [])
            : $this->skills->manifestFor($scope);
        $entry = $manifest->findSkill($skill);

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

        // Optional context (e.g. open Files at a folder): appended to the entry
        // URL as ?path= and reflected in the window title.
        $entryUrl = (string) $entry->entry;
        $title = $entry->name;
        $ref = trim($payload->getRef());
        if ($ref !== '') {
            $entryUrl .= (str_contains($entryUrl, '?') ? '&' : '?') . 'ref=' . rawurlencode($ref);

            // The window is named after the place, not after the app: a person
            // opening five pages needs to tell those windows apart.
            $mapped = $this->graph->nodeByRef($ref);
            if ($mapped !== null && $mapped->getTitle() !== '') {
                $title = $mapped->getTitle();
            }
        }

        $path = trim($payload->getPath());
        if ($path !== '') {
            $entryUrl .= (str_contains($entryUrl, '?') ? '&' : '?') . 'path=' . rawurlencode($path);
            $base = basename($path);
            if ($base !== '') {
                $title = $base;
            }
        }

        $dialog = $this->dialogs->open(
            skill: $entry->name,
            title: $title,
            icon: $entry->icon,
            entry: $entryUrl,
            parentId: $payload->getParentId(),
        );

        return $resource
            ->setContent((string) json_encode(['dialog' => $dialog], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            ->setHeader('Content-Type', 'application/json');
    }
}
