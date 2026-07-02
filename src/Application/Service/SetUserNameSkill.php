<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service;

use Semitexa\Llm\Attribute\AsAiSkill;
use Semitexa\Llm\Domain\Contract\InvocableSkillInterface;
use Semitexa\Llm\Domain\Enum\AiArgumentPolicy;
use Semitexa\Llm\Domain\Enum\AiConfirmationMode;
use Semitexa\Llm\Domain\Enum\AiRiskLevel;

/**
 * Record the user's own name by talking to the OS ("my name is Alex", "call me
 * Sam"). The counterpart to {@see RenameAssistantSkill}: that renames the
 * assistant, this records what to call the user. Persisted via
 * {@see OsPreferences}; used by the greeting instead of the onboarding ask.
 */
#[AsAiSkill(
    name: 'set-user-name',
    summary: "Record the user's own name — what the assistant should call them.",
    useWhen: 'The user tells you their name or how to address them (e.g. "my name is Alex", "call me Sam", "мене звати Тарас"). Put the name in the `name` argument.',
    avoidWhen: 'The user is renaming YOU (the assistant) — use rename-assistant for that.',
    riskLevel: AiRiskLevel::Low,
    confirmation: AiConfirmationMode::Never,
    argumentPolicy: AiArgumentPolicy::Allowlisted,
    exposeArguments: ['name'],
    channels: ['web'],
)]
final class SetUserNameSkill implements InvocableSkillInterface
{
    public function invoke(array $arguments): string
    {
        $name = trim((string) ($arguments['name'] ?? ''));
        if ($name === '') {
            return 'What would you like me to call you?';
        }

        $prefs = (new OsPreferences())->setUserName($name);

        return 'Nice to meet you, ' . $prefs['user_name'] . '.';
    }
}
