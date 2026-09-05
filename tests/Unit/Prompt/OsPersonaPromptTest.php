<?php

declare(strict_types=1);

namespace Semitexa\Os\Tests\Unit\Prompt;

use PHPUnit\Framework\TestCase;
use Semitexa\Os\Application\Prompt\OsPersonaPrompt;
use Semitexa\Prompt\Application\Service\PromptRegistry;
use Semitexa\Prompt\Application\Service\PromptRenderer;
use Semitexa\Prompt\Domain\Contract\PromptRepositoryInterface;
use Semitexa\Prompt\Domain\Model\PromptTemplate;

final class OsPersonaPromptTest extends TestCase
{
    /** A one-entry repository so render-by-id stays hermetic (no full discovery). */
    private function repository(): PromptRepositoryInterface
    {
        $templates = (new PromptRegistry())->buildFromClasses([OsPersonaPrompt::class]);

        return new class($templates) implements PromptRepositoryInterface {
            /** @param array<string, PromptTemplate> $templates */
            public function __construct(private array $templates) {}
            public function get(string $id): PromptTemplate
            {
                return $this->templates[$id];
            }
            public function tryGet(string $id): ?PromptTemplate
            {
                return $this->templates[$id] ?? null;
            }
            public function has(string $id): bool
            {
                return isset($this->templates[$id]);
            }
            public function all(): array
            {
                return array_values($this->templates);
            }
        };
    }

    /**
     * The self-binding prompt carries typed data; render() exposes it as the
     * `prompt` object and the template reads its getters. Known name → the
     * greeting branch, byte-identical.
     */
    public function testBoundPromptRendersTheGreeting(): void
    {
        $rendered = (new PromptRenderer())->render(
            (new OsPersonaPrompt())->withData('Solomiia', 'Taras'),
            [],
            $this->repository(),
        );

        self::assertStringStartsWith(
            'You are Solomiia, the assistant at the heart of Semitexa OS — a personal, intent-first operating system. You are speaking with Taras.',
            $rendered->system,
        );
        self::assertStringContainsString('Speak as Solomiia in the first person', $rendered->system);
    }

    public function testBoundPromptWithoutNameRendersAskForNameGuidance(): void
    {
        $rendered = (new PromptRenderer())->render(
            (new OsPersonaPrompt())->withData('Solomiia', ''),
            [],
            $this->repository(),
        );

        self::assertStringStartsWith(
            "You are Solomiia, the assistant at the heart of Semitexa OS — a personal, intent-first operating system. You do not know the user's name yet.",
            $rendered->system,
        );
        self::assertStringContainsString('record it with the set-user-name skill', $rendered->system);
    }

    /**
     * The persona carries a character, not just a job title — and two rules
     * that make her trustworthy: she does not invent, and she does not report
     * an action as done before the skill result says so. Those are the lines
     * an edit for brevity would quietly drop.
     */
    public function testThePersonaCarriesHerCharacterAndTheTwoHardRules(): void
    {
        $system = (new PromptRenderer())->render(
            (new OsPersonaPrompt())->withData('Solomiia', 'Taras'),
            [],
            $this->repository(),
        )->system;

        foreach (['warm', 'quick-witted', 'curious', 'quietly funny'] as $trait) {
            self::assertStringContainsString($trait, $system, "the persona lost its '{$trait}'");
        }
        self::assertStringContainsString('Never invent a fact, a number or an outcome', $system);
        self::assertStringContainsString('Never say you have done something until a skill result confirms it', $system);
    }

    public function testTemplateGettersResolveOnTheBoundObject(): void
    {
        // The names the template reads via dot-access (`{{ prompt.assistantName }}`).
        $slots = (new PromptRegistry())->buildFromClasses([OsPersonaPrompt::class])['os.persona']->variableNames();
        sort($slots);
        self::assertSame(['assistantName', 'userName'], $slots);

        // Each one must be a real getter on the class returning the typed data —
        // there is no variables() map to drift; the object stays in sync with its
        // .twig slots by construction (a missing getter fails render under strict).
        $prompt = (new OsPersonaPrompt())->withData('Solomiia', 'Taras');
        foreach ($slots as $getter) {
            self::assertTrue(method_exists($prompt, $getter), "missing getter: {$getter}");
        }
        self::assertSame('Solomiia', $prompt->assistantName());
        self::assertSame('Taras', $prompt->userName());
    }
}
