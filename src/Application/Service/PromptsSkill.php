<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service;

use Semitexa\Llm\Attribute\AsAiSkill;
use Semitexa\Llm\Domain\Enum\AiArgumentPolicy;
use Semitexa\Llm\Domain\Enum\AiConfirmationMode;
use Semitexa\Llm\Domain\Enum\AiRiskLevel;

/**
 * The Prompts UI-skill: a live editor for the prompt catalog. It lists every
 * registered prompt and lets an operator override any prompt's system text for
 * the current tenant (with catalog fallback), backed by the semitexa-prompt
 * DB override layer. A UI-skill — identity + entry, nothing to "run"; its body
 * is rendered by `/os/app/prompts` and hosted in the Focus zone.
 */
#[AsAiSkill(
    name: 'Prompts',
    summary: 'Browse and live-edit the prompt catalog; override any prompt for this tenant.',
    useWhen: 'The user wants to view, edit, customise or override the assistant\'s prompts.',
    riskLevel: AiRiskLevel::Low,
    confirmation: AiConfirmationMode::Never,
    argumentPolicy: AiArgumentPolicy::None,
    channels: ['ui'],
    icon: 'file-pen-line',
    entry: '/os/app/prompts',
)]
final class PromptsSkill {}
