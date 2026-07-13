<?php

declare(strict_types=1);

namespace Semitexa\Os\Tests\Unit\Prompt;

use PHPUnit\Framework\TestCase;
use Semitexa\Os\Application\Prompt\LoopContinueNudgePrompt;
use Semitexa\Os\Application\Prompt\ReplyLanguagePrompt;
use Semitexa\Prompt\Application\Service\PromptRegistry;
use Semitexa\Prompt\Application\Service\PromptRenderer;

/**
 * Byte-identity guards for the two SkillLoopRunner instruction fragments migrated
 * onto the catalog: the continue-or-answer nudge and the reply-language pin. Both
 * must reproduce the exact text of the former inline const / fragment.
 */
final class SkillLoopFragmentPromptsTest extends TestCase
{
    public function testContinueNudgeIsByteIdenticalToTheLegacyConst(): void
    {
        $expected = "Continue fulfilling the user's original request using this result."
            . " If the request is now fully satisfied, respond with an \"answer\" that gives the user the result directly."
            . ' Otherwise propose the next skill.';

        self::assertSame($expected, rtrim((new PromptRegistry())->buildFromClasses([LoopContinueNudgePrompt::class])['os.loop.continue-nudge']->system));
    }

    public function testReplyLanguageLineIsByteIdenticalToTheLegacyFragment(): void
    {
        $language = 'Ukrainian (українська)';
        $expected = "Always reply in {$language}, regardless of the language the user writes in — unless they explicitly ask you to switch (then use the set-locale skill).";

        $template = (new PromptRegistry())->buildFromClasses([ReplyLanguagePrompt::class])['os.reply-language'];
        $rendered = (new PromptRenderer())->renderTemplate($template, ['language' => $language]);

        self::assertSame($expected, $rendered->system);
    }

    public function testFragmentMetadata(): void
    {
        $nudge = (new PromptRegistry())->buildFromClasses([LoopContinueNudgePrompt::class])['os.loop.continue-nudge'];
        self::assertSame('os', $nudge->channel);
        self::assertSame([], $nudge->variableNames());

        $lang = (new PromptRegistry())->buildFromClasses([ReplyLanguagePrompt::class])['os.reply-language'];
        self::assertSame(['language'], $lang->variableNames());
    }
}
