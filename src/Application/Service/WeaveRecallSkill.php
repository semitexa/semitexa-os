<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service;

use Semitexa\Llm\Attribute\AsAiSkill;
use Semitexa\Llm\Domain\Contract\InvocableSkillInterface;
use Semitexa\Llm\Domain\Enum\AiArgumentPolicy;
use Semitexa\Llm\Domain\Enum\AiConfirmationMode;
use Semitexa\Llm\Domain\Enum\AiRiskLevel;

/**
 * Let the assistant read back the user's Weave — what they're working on, who
 * and what is in their world — so it can answer from the graph rather than
 * guessing. Read-only, no gate.
 */
#[AsAiSkill(
    name: 'recall',
    summary: 'Recall what is in the user\'s world — their projects, people and connections — from their graph.',
    useWhen: 'The user asks about their own world: what they\'re working on, who they work with, what you know about them, or what relates to a given thing ("what am I working on?", "who do I work with?", "what do you know about me?", "what\'s connected to the coastal-house project?"). Put any focus term in `query` (optional; omit for an overview).',
    avoidWhen: 'The user wants to record something new (use remember), open an app, or is asking a general/world-knowledge question unrelated to their own graph.',
    riskLevel: AiRiskLevel::Low,
    confirmation: AiConfirmationMode::Never,
    argumentPolicy: AiArgumentPolicy::Allowlisted,
    exposeArguments: ['query'],
    argumentHints: [
        'query' => 'Optional focus term — the NAME of a thing/person; omit for a full overview.',
    ],
    channels: ['web'],
)]
final class WeaveRecallSkill implements InvocableSkillInterface
{
    public function invoke(array $arguments): string
    {
        return (new OsGraph())->recall((string) ($arguments['query'] ?? ''));
    }
}
