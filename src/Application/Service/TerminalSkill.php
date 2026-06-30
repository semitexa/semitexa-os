<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service;

use Semitexa\Llm\Attribute\AsAiSkill;
use Semitexa\Llm\Domain\Enum\AiArgumentPolicy;
use Semitexa\Llm\Domain\Enum\AiConfirmationMode;
use Semitexa\Llm\Domain\Enum\AiRiskLevel;

/**
 * The Terminal UI-skill: a dialog that hosts the console-skill surface. This is
 * where console skills (the `console` channel) live and run — keeping the raw
 * skill list out of the main intent-first UI (operator decision: console skills
 * belong in a terminal, not on screen). Entry route `/os/app/terminal`.
 */
#[AsAiSkill(
    name: 'Terminal',
    summary: 'Open a terminal to run console skills directly.',
    useWhen: 'The user wants a terminal, a console, or to run a console command/skill directly.',
    riskLevel: AiRiskLevel::Low,
    confirmation: AiConfirmationMode::Never,
    argumentPolicy: AiArgumentPolicy::None,
    channels: ['ui'],
    icon: 'terminal',
    entry: '/os/app/terminal',
)]
final class TerminalSkill
{
}
