<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service;

use Semitexa\Llm\Attribute\AsAiSkill;
use Semitexa\Llm\Domain\Contract\InvocableSkillInterface;
use Semitexa\Llm\Domain\Enum\AiArgumentPolicy;
use Semitexa\Llm\Domain\Enum\AiConfirmationMode;
use Semitexa\Llm\Domain\Enum\AiRiskLevel;

/**
 * Intent-route for attaching a real folder to the Weave: "прив'яжи папку
 * semitexa до проєкту Semitexa". Folders live on the USER'S machine — only the
 * client-side bridge can see them — so this skill's job is routing + clean
 * arguments; the shell (seeing it executed) resolves the folder name via the
 * bridge and posts /os/weave/attach, which narrates the result back into the
 * chat. Files become leaf nodes of the world, by voice.
 */
#[AsAiSkill(
    name: 'attach-folder',
    summary: 'Attach a real folder from the user\'s machine to their world (the Weave graph).',
    useWhen: 'The user asks to attach / link / add a FOLDER or project directory to their world or to an entity — "прив\'яжи папку semitexa до проєкту Semitexa", "attach the alraie folder to my world", "додай теку проєкту X у мій світ". Put the folder\'s NAME in `folder`; optionally the entity to hang it under in `connect_to`.',
    avoidWhen: 'The user wants to BROWSE files (open the Files app), remember a non-file fact (remember), or see something in the Workspace (show-in-world).',
    riskLevel: AiRiskLevel::Low,
    confirmation: AiConfirmationMode::Never,
    argumentPolicy: AiArgumentPolicy::Allowlisted,
    exposeArguments: ['folder', 'connect_to'],
    argumentHints: [
        'folder' => 'The folder\'s NAME or a distinctive fragment (e.g. "semitexa.dev", "alraie") — not a path, not a sentence.',
        'connect_to' => 'Optional: name of the EXISTING entity to hang the folder under (e.g. "Semitexa"). Omit to auto-match.',
    ],
    channels: ['web'],
)]
final class WeaveAttachSkill implements InvocableSkillInterface
{
    public function invoke(array $arguments): string
    {
        $folder = trim((string) ($arguments['folder'] ?? ''));
        if ($folder === '') {
            return 'Which folder should I attach?';
        }

        // The actual filesystem lookup happens client-side (the bridge lives on
        // the user's machine); the shell picks this outcome up and completes it.
        return 'Looking for the "' . $folder . '" folder among your files…';
    }
}
