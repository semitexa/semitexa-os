<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service;

use Semitexa\Llm\Attribute\AsAiSkill;
use Semitexa\Llm\Domain\Enum\AiArgumentPolicy;
use Semitexa\Llm\Domain\Enum\AiConfirmationMode;
use Semitexa\Llm\Domain\Enum\AiRiskLevel;

/**
 * The first Semitexa OS UI-skill: a notes editor. Unlike a console/invocable
 * skill, it does not execute and return text — it raises a persistent
 * interactive dialog (the `'ui'` channel) whose body is rendered by its `entry`
 * route (`/os/app/notes`) and hosted in the Focus zone. No #[AsCommand], no
 * InvocableSkillInterface: a UI-skill is identity + entry, nothing to "run".
 */
#[AsAiSkill(
    name: 'Notes',
    summary: 'Open a notes editor to write or jot something down.',
    useWhen: 'The user wants to write, jot, draft or keep notes.',
    riskLevel: AiRiskLevel::Low,
    confirmation: AiConfirmationMode::Never,
    argumentPolicy: AiArgumentPolicy::None,
    channels: ['ui'],
    icon: 'sticky-note',
    entry: '/os/app/notes',
)]
final class NotesSkill
{
}
