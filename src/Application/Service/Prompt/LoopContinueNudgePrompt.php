<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service\Prompt;

use Semitexa\Prompt\Attribute\AsPrompt;
use Semitexa\Prompt\Domain\Contract\PromptDefinitionInterface;

/**
 * The continue-or-answer nudge appended to the "Results so far" message in the
 * orchestration loop, telling the planner to keep fulfilling the original
 * request or to answer when it is satisfied. Migrated out of the
 * {@see \Semitexa\Os\Application\Service\SkillLoopRunner}::CONTINUE_NUDGE const.
 *
 * No variables — a fixed instruction fragment.
 */
#[AsPrompt(
    id: self::ID,
    channel: 'os',
    description: 'Orchestration-loop continue-or-answer nudge appended to the results-so-far message.',
)]
final class LoopContinueNudgePrompt implements PromptDefinitionInterface
{
    public const ID = 'os.loop.continue-nudge';

    public function system(): string
    {
        return <<<'PROMPT'
        Continue fulfilling the user's original request using this result. If the request is now fully satisfied, respond with an "answer" that gives the user the result directly. Otherwise propose the next skill.
        PROMPT;
    }
}
