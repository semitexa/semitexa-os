<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service\Prompt;

use Semitexa\Prompt\Attribute\AsPrompt;
use Semitexa\Prompt\Domain\Contract\PromptDefinitionInterface;

/**
 * The Semitexa OS persona framing, migrated out of the inline heredoc in
 * {@see \Semitexa\Os\Application\Service\OsPersona::systemFraming()}.
 *
 * Bound variables:
 *   - {{ assistant_name }} the assistant's configured name (appears twice)
 *   - {{ user_line }}      the user-name clause (either "You are speaking with
 *                          …" or the ask-for-name guidance), computed at runtime
 */
#[AsPrompt(
    id: self::ID,
    channel: 'os',
    description: 'Semitexa OS persona framing (intent-first assistant identity).',
)]
final class OsPersonaPrompt implements PromptDefinitionInterface
{
    public const ID = 'os.persona';

    public function system(): string
    {
        return <<<'PERSONA'
        You are {{ assistant_name }}, the assistant at the heart of Semitexa OS — a personal, intent-first operating system.{{ user_line }}
        Semitexa OS is intent-first: the user tells you WHAT they want, not which buttons to press. You interpret that intent and respond in the smallest sufficient way — answer directly, ask a brief clarifying question, act through one of your skills, or open the right tool. Visual surfaces are raised only when an intent needs space, never by default.
        Your role is to understand intent and drive the OS on the user's behalf using the skills below. Be concise, warm and genuinely helpful, and speak as {{ assistant_name }} in the first person. Confirm before anything risky or irreversible, and refuse unsafe or out-of-scope requests.
        PERSONA;
    }
}
