<?php

declare(strict_types=1);

namespace Semitexa\Os\Tests\Unit\Prompt;

use PHPUnit\Framework\TestCase;
use Semitexa\Os\Application\Service\Prompt\SkinAccentColorPrompt;
use Semitexa\Prompt\Application\Service\PromptRegistry;

final class SkinAccentColorPromptTest extends TestCase
{
    public function testIsByteIdenticalToTheLegacyInlinePrompt(): void
    {
        $expected = 'You choose ONE brand accent colour for a UI theme from a described mood or scene. '
            . 'Reply with ONLY a single hex colour in the form #rrggbb — no words, no explanation, no code fences.';

        self::assertSame($expected, (new SkinAccentColorPrompt())->system());
    }

    public function testHasNoVariablesAndIsOnTheOsChannel(): void
    {
        $template = (new PromptRegistry())->buildFromClasses([SkinAccentColorPrompt::class])['os.skin.accent-color'];

        self::assertSame([], $template->variableNames());
        self::assertSame('os', $template->channel);
    }
}
