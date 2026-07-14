<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Prompt;

use Semitexa\Prompt\Attribute\AsPrompt;
use Semitexa\Prompt\Domain\Contract\BoundPromptInterface;

/**
 * Thin, self-binding prompt — body in resources/prompts/os.reply-language.twig.
 */
#[AsPrompt(
    id: self::ID,
    channel: 'os',
    template: 'resources/prompts/os.reply-language.twig',
    description: 'Reply-language pin appended to the planner persona ({{ language }}).',
)]
final class ReplyLanguagePrompt implements BoundPromptInterface
{
    public const ID = 'os.reply-language';

    public function __construct(
        private readonly ?string $language = null,
    ) {}

    public function withData(string $language): self
    {
        return new self($language);
    }

    public function promptId(): string
    {
        return self::ID;
    }

    public function language(): string
    {
        return (string) $this->language;
    }
}
