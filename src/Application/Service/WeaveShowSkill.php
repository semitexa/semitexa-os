<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service;

use Semitexa\Llm\Attribute\AsAiSkill;
use Semitexa\Llm\Domain\Contract\InvocableSkillInterface;
use Semitexa\Llm\Domain\Enum\AiArgumentPolicy;
use Semitexa\Llm\Domain\Enum\AiConfirmationMode;
use Semitexa\Llm\Domain\Enum\AiRiskLevel;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Weave\Domain\Contract\GraphStoreInterface;

/**
 * Intent-route into the contextual Workspace: "покажи Semitexa у моєму світі"
 * routes here, the skill confirms the match, and the shell (seeing this skill
 * executed) opens the Workspace focused on it via the feed's focusQuery
 * resolution — visual navigation by intent, the concept's way of finding
 * things (never a folder tree, never a menu).
 */
#[AsAiSkill(
    name: 'show-in-world',
    summary: 'Open the Workspace focused on one thing in the user\'s world (its local graph).',
    useWhen: 'The user wants to SEE something in their world / Workspace graph — "покажи Semitexa у моєму світі", "show Anna in my workspace", "відкрий світ навколо проєкту X", "what\'s around Y — show me". Put the thing\'s name in `query`.',
    avoidWhen: 'The user asks a QUESTION about their world and expects a spoken answer (use recall), wants to record a new fact (use remember), or to open an app.',
    riskLevel: AiRiskLevel::Low,
    confirmation: AiConfirmationMode::Never,
    argumentPolicy: AiArgumentPolicy::Allowlisted,
    exposeArguments: ['query'],
    argumentHints: [
        'query' => 'The NAME of the thing to focus on (e.g. "Semitexa", "Олена") — a name, not a sentence.',
    ],
    channels: ['web'],
)]
final class WeaveShowSkill implements InvocableSkillInterface
{
    /** Tenant-aware via container injection (SkillLoopRunner's DI resolver). */
    #[InjectAsReadonly]
    protected GraphStoreInterface $graph;

    public function invoke(array $arguments): string
    {
        $query = trim((string) ($arguments['query'] ?? ''));
        if ($query === '') {
            return OsReplies::say('weave.show.ask', [], 'What should I focus your world on?');
        }

        $found = $this->graph->search($query, 1);
        if (!isset($found[0])) {
            return OsReplies::say(
                'weave.show.unknown',
                ['query' => $query],
                'I don\'t have "{{query}}" in your world yet — tell me about it and I\'ll remember.',
            );
        }

        return OsReplies::say(
            'weave.show.focusing',
            ['title' => $found[0]->getTitle()],
            'Focusing your world on "{{title}}" — opening the Workspace.',
        );
    }
}
