<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service;

use Semitexa\Llm\Attribute\AsAiSkill;
use Semitexa\Llm\Domain\Enum\AiArgumentPolicy;
use Semitexa\Llm\Domain\Enum\AiConfirmationMode;
use Semitexa\Llm\Domain\Enum\AiRiskLevel;

/**
 * The Updates UI-skill: a "What's new" dialog showing the installed release
 * set, release notes for recently applied package changes, and the update run
 * history. Like {@see SettingsSkill} it is identity + entry only — the body is
 * rendered by `/os/app/updates`. Opened via intent ("what's new", "updates",
 * "update history").
 */
#[AsAiSkill(
    name: 'Updates',
    summary: 'Open Updates to see what is new — applied changes, release notes, and update history.',
    useWhen: 'The user asks what is new, what changed, about updates, versions, or the update history.',
    riskLevel: AiRiskLevel::Low,
    confirmation: AiConfirmationMode::Never,
    argumentPolicy: AiArgumentPolicy::None,
    channels: ['ui'],
    icon: 'refresh-cw',
    entry: '/os/app/updates',
)]
final class UpdatesSkill
{
}
