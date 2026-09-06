<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Llm\Attribute\AsAiSkill;
use Semitexa\Llm\Domain\Contract\InvocableSkillInterface;
use Semitexa\Llm\Domain\Enum\AiArgumentPolicy;
use Semitexa\Llm\Domain\Enum\AiConfirmationMode;
use Semitexa\Llm\Domain\Enum\AiRiskLevel;

/**
 * Let the assistant record something into the user's Weave — a project, person,
 * place, topic they're involved with — as a node connected to their world.
 * This is how conversation grows the graph. Low-risk and reversible (a node in
 * the Workspace), so it runs without a gate.
 */
#[AsAiSkill(
    name: 'remember',
    summary: 'Record something in the user\'s world (a project, person, place, topic) into their graph.',
    useWhen: 'The user states a fact about their life/work to keep — a project they\'re on, a person they work with, a place, a topic they\'re into ("remember I\'m working on the coastal-house project", "add my colleague Anna", "note that I\'m learning Rust", "I\'m part of the design team"). `what` is ONLY the short name of the thing ("Anna", "coastal-house project") — the rest of the sentence goes into `kind`/`connect_to`, never into `what`.',
    avoidWhen: 'The user is asking a question, recalling (use recall), chatting, or wants to open an app / restyle the interface / manage tasks — not recording a new fact about their world.',
    riskLevel: AiRiskLevel::Low,
    confirmation: AiConfirmationMode::Never,
    argumentPolicy: AiArgumentPolicy::Allowlisted,
    exposeArguments: ['what', 'kind', 'connect_to'],
    argumentHints: [
        'what' => 'The short NAME of the thing only, max 6 words (e.g. "Anna", "coastal-house project") — NEVER the whole sentence.',
        'kind' => 'One of: project|person|place|topic|task|event|org.',
        'connect_to' => 'Name of an EXISTING thing in the user\'s world this relates to (e.g. the project a person works on). Omit to link to the user.',
    ],
    channels: ['web'],
)]
final class WeaveRememberSkill implements InvocableSkillInterface
{
    /** Tenant-aware via container injection (SkillLoopRunner's DI resolver). */
    #[InjectAsReadonly]
    protected OsGraph $graph;

    public function invoke(array $arguments): string
    {
        $what = trim((string) ($arguments['what'] ?? ''));
        if ($what === '') {
            return OsReplies::say('weave.remember.ask', [], 'What would you like me to remember about your world?');
        }

        try {
            $result = $this->graph->remember(
                $what,
                (string) ($arguments['kind'] ?? ''),
                (string) ($arguments['connect_to'] ?? ''),
            );
        } catch (\Throwable $e) {
            return OsReplies::say(
                'weave.remember.failed',
                ['reason' => $e->getMessage()],
                "I couldn't record that: {{reason}}",
            );
        }

        $parent = $result['parent'];
        $link = ($parent->getProperties()['is_self'] ?? false) === true ? 'you' : '"' . $parent->getTitle() . '"';

        return OsReplies::say(
            'weave.remember.added',
            ['title' => $result['node']->getTitle(), 'link' => $link],
            'Added "{{title}}" to your world, linked to {{link}}. Open Workspace to see it.',
        );
    }
}
