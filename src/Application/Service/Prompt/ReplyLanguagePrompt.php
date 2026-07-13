<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service\Prompt;

use Semitexa\Prompt\Attribute\AsPrompt;
use Semitexa\Prompt\Domain\Contract\PromptDefinitionInterface;

/**
 * The reply-language pin appended to the planner persona so the assistant keeps
 * answering in the user's locale language. Migrated out of the inline fragment
 * in {@see \Semitexa\Os\Application\Service\SkillLoopRunner}::plannerPersona().
 *
 * The caller joins this onto the persona with a leading newline, so the fragment
 * itself carries none. One variable: {{ language }} (a human-readable language
 * name, e.g. "Ukrainian (українська)").
 */
#[AsPrompt(
    id: self::ID,
    channel: 'os',
    description: 'Reply-language pin appended to the planner persona ({{ language }}).',
)]
final class ReplyLanguagePrompt implements PromptDefinitionInterface
{
    public const ID = 'os.reply-language';

    public function system(): string
    {
        return <<<'PROMPT'
        Always reply in {{ language }}, regardless of the language the user writes in — unless they explicitly ask you to switch (then use the set-locale skill).
        PROMPT;
    }
}
