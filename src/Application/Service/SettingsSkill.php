<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service;

use Semitexa\Llm\Attribute\AsAiSkill;
use Semitexa\Llm\Domain\Enum\AiArgumentPolicy;
use Semitexa\Llm\Domain\Enum\AiConfirmationMode;
use Semitexa\Llm\Domain\Enum\AiRiskLevel;

/**
 * The Settings UI-skill: a dialog for personalising the OS (v0: rename the
 * assistant). Like {@see NotesSkill}, it raises a persistent dialog rendered by
 * its `entry` route (`/os/app/settings`) in the Focus zone — nothing to "run",
 * just identity + entry. Opened via intent ("open settings", "preferences").
 */
#[AsAiSkill(
    name: 'Settings',
    summary: 'Open Settings to personalise the OS — rename the assistant and more.',
    useWhen: 'The user wants to open settings or preferences, or to personalise/configure the OS.',
    riskLevel: AiRiskLevel::Low,
    confirmation: AiConfirmationMode::Never,
    argumentPolicy: AiArgumentPolicy::None,
    channels: ['ui'],
    icon: 'settings',
    entry: '/os/app/settings',
)]
final class SettingsSkill
{
}
