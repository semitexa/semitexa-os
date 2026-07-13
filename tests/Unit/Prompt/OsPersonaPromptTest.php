<?php

declare(strict_types=1);

namespace Semitexa\Os\Tests\Unit\Prompt;

use PHPUnit\Framework\TestCase;
use Semitexa\Os\Application\Prompt\OsPersonaPrompt;
use Semitexa\Prompt\Application\Service\PromptRegistry;
use Semitexa\Prompt\Application\Service\PromptRenderer;
use Semitexa\Prompt\Domain\Model\PromptTemplate;

final class OsPersonaPromptTest extends TestCase
{
    private function template(): PromptTemplate
    {
        return (new PromptRegistry())->buildFromClasses([OsPersonaPrompt::class])['os.persona'];
    }

    /**
     * The user-name branch is native Twig now ({% if user_name %}), so the
     * consumer passes the raw name; the template builds the greeting. Rendered
     * output must be byte-identical to the pre-simplification persona.
     */
    public function testKnownUserNameRendersTheGreeting(): void
    {
        $rendered = (new PromptRenderer())->renderTemplate($this->template(), [
            'assistant_name' => 'Semi',
            'user_name' => 'Taras',
        ]);

        self::assertStringStartsWith(
            'You are Semi, the assistant at the heart of Semitexa OS — a personal, intent-first operating system. You are speaking with Taras.',
            $rendered->system,
        );
        self::assertStringContainsString('speak as Semi in the first person', $rendered->system);
        self::assertStringNotContainsString('{{', $rendered->system);
    }

    public function testUnknownUserNameRendersTheAskForNameGuidance(): void
    {
        $rendered = (new PromptRenderer())->renderTemplate($this->template(), [
            'assistant_name' => 'Semi',
            'user_name' => '',
        ]);

        self::assertStringStartsWith(
            "You are Semi, the assistant at the heart of Semitexa OS — a personal, intent-first operating system. You do not know the user's name yet.",
            $rendered->system,
        );
        self::assertStringContainsString('record it with the set-user-name skill', $rendered->system);
    }

    public function testTemplateDeclaresItsTwoVariables(): void
    {
        $vars = $this->template()->variableNames();
        sort($vars);

        self::assertSame(['assistant_name', 'user_name'], $vars);
    }
}
