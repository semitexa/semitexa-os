<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Prompt;

use Semitexa\Prompt\Attribute\AsPrompt;

/**
 * Thin prompt definition — the body lives in resources/prompts/os.reply-language.twig.
 */
#[AsPrompt(
    id: self::ID,
    channel: 'os',
    template: 'resources/prompts/os.reply-language.twig',
    description: 'Reply-language pin appended to the planner persona ({{ language }}).',
)]
final class ReplyLanguagePrompt
{
    public const ID = 'os.reply-language';
}
