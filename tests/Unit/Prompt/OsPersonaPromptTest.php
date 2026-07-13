<?php

declare(strict_types=1);

namespace Semitexa\Os\Tests\Unit\Prompt;

use PHPUnit\Framework\TestCase;
use Semitexa\Os\Application\Service\Prompt\OsPersonaPrompt;
use Semitexa\Prompt\Application\Service\PromptRegistry;
use Semitexa\Prompt\Application\Service\PromptRenderer;

final class OsPersonaPromptTest extends TestCase
{
    /**
     * The template must equal the pre-migration heredoc byte-for-byte, with only
     * the two interpolations rewritten to catalog variables. The golden fixture
     * was produced mechanically from the git-original OsPersona.
     */
    public function testTemplateIsByteIdenticalToLegacyHeredoc(): void
    {
        $golden = (string) file_get_contents(__DIR__ . '/fixtures/os-persona.template.golden.txt');

        self::assertSame($golden, (new OsPersonaPrompt())->system());
    }

    public function testRenderBindsBothAssistantOccurrencesAndTheUserLine(): void
    {
        $template = (new PromptRegistry())->buildFromClasses([OsPersonaPrompt::class])['os.persona'];

        $rendered = (new PromptRenderer())->renderTemplate($template, [
            'assistant_name' => 'Semi',
            'user_line' => ' You are speaking with Taras.',
        ]);

        self::assertStringContainsString('You are Semi, the assistant at the heart of Semitexa OS', $rendered->system);
        self::assertStringContainsString('operating system. You are speaking with Taras.', $rendered->system);
        self::assertStringContainsString('speak as Semi in the first person', $rendered->system);
        self::assertStringNotContainsString('{{', $rendered->system);
    }

    public function testTemplateDeclaresItsTwoVariables(): void
    {
        $vars = (new PromptRegistry())->buildFromClasses([OsPersonaPrompt::class])['os.persona']->variableNames();
        sort($vars);

        self::assertSame(['assistant_name', 'user_line'], $vars);
    }
}
