<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service;

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
    useWhen: 'The user states a fact about their life/work to keep — a project they\'re on, a person they work with, a place, a topic they\'re into ("remember I\'m working on the coastal-house project", "add my colleague Anna", "note that I\'m learning Rust", "I\'m part of the design team"). Put the thing itself in `what`; optionally its `kind` (project|person|place|topic|task|event|org) and `connect_to` (an existing thing it relates to).',
    avoidWhen: 'The user is asking a question, recalling (use recall), chatting, or wants to open an app / restyle the interface / manage tasks — not recording a new fact about their world.',
    riskLevel: AiRiskLevel::Low,
    confirmation: AiConfirmationMode::Never,
    argumentPolicy: AiArgumentPolicy::Allowlisted,
    exposeArguments: ['what', 'kind', 'connect_to'],
    channels: ['web'],
)]
final class WeaveRememberSkill implements InvocableSkillInterface
{
    public function invoke(array $arguments): string
    {
        $what = trim((string) ($arguments['what'] ?? ''));
        if ($what === '') {
            return 'What would you like me to remember about your world?';
        }

        try {
            $result = (new OsGraph())->remember(
                $what,
                (string) ($arguments['kind'] ?? ''),
                (string) ($arguments['connect_to'] ?? ''),
            );
        } catch (\Throwable $e) {
            return "I couldn't record that: " . $e->getMessage();
        }

        $parent = $result['parent'];
        $link = ($parent->properties['is_self'] ?? false) === true ? 'you' : '"' . $parent->title . '"';

        return 'Added "' . $result['node']->title . '" to your world, linked to ' . $link . '. Open Workspace to see it.';
    }
}
