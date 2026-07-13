<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Prompt;

use Semitexa\Prompt\Attribute\AsPrompt;
use Semitexa\Prompt\Domain\Contract\BoundPromptInterface;

/**
 * Thin, self-binding prompt — body in resources/prompts/os.loop.continue-nudge.twig.
 */
#[AsPrompt(
    id: self::ID,
    channel: 'os',
    template: 'resources/prompts/os.loop.continue-nudge.twig',
    description: 'Orchestration-loop continue-or-answer nudge appended to the results-so-far message.',
)]
final class LoopContinueNudgePrompt implements BoundPromptInterface
{
    public const ID = 'os.loop.continue-nudge';

    public function promptId(): string
    {
        return self::ID;
    }
}
