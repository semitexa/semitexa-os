<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service;

use Semitexa\Llm\Attribute\AsAiSkill;
use Semitexa\Llm\Domain\Contract\InvocableSkillInterface;
use Semitexa\Llm\Domain\Enum\AiArgumentPolicy;
use Semitexa\Llm\Domain\Enum\AiConfirmationMode;
use Semitexa\Llm\Domain\Enum\AiRiskLevel;

/**
 * Rename the assistant by talking to the OS — the intent-first way to change
 * what you call it ("call yourself Jarvis", "your name is Aria"). The planner
 * extracts the new name into the `name` argument; this skill persists it via
 * {@see OsPreferences}. Low-risk and reversible, so it runs without a gate.
 *
 * `name` is exposed but not required: if the planner doesn't catch a name we
 * ask back rather than fail, keeping the loop conversational.
 */
#[AsAiSkill(
    name: 'rename-assistant',
    summary: 'Rename the assistant — change the name the user calls you by.',
    useWhen: 'The user asks to rename you or change your name (e.g. "call yourself Jarvis", "your name is Aria", "зміни своє імʼя на Джарвіс"). Put the requested new name in the `name` argument.',
    avoidWhen: 'The user is naming something else — a file, a note, a person — not you.',
    riskLevel: AiRiskLevel::Low,
    confirmation: AiConfirmationMode::Never,
    argumentPolicy: AiArgumentPolicy::Allowlisted,
    exposeArguments: ['name'],
    argumentHints: [
        'name' => 'The new assistant name only (e.g. "Jarvis") — a name, not a sentence.',
    ],
    channels: ['web'],
)]
final class RenameAssistantSkill implements InvocableSkillInterface
{
    public function invoke(array $arguments): string
    {
        $name = trim((string) ($arguments['name'] ?? ''));
        if ($name === '') {
            return "I didn't catch a new name — what would you like to call me?";
        }

        $prefs = (new OsPreferences())->setAssistantName($name);

        return 'Done — you can call me ' . $prefs['assistant_name'] . ' from now on.';
    }
}
