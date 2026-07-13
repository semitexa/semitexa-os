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
     * The self-binding prompt carries typed data; render() reads its id +
     * variables(). Known name → the greeting branch, byte-identical.
     */
    public function testBoundPromptRendersTheGreeting(): void
    {
        $rendered = (new PromptRenderer())->render(
            (new OsPersonaPrompt())->withData('Semi', 'Taras'),
            [],
            $this->repository(),
        );

        self::assertStringStartsWith(
            'You are Semi, the assistant at the heart of Semitexa OS — a personal, intent-first operating system. You are speaking with Taras.',
            $rendered->system,
        );
        self::assertStringContainsString('speak as Semi in the first person', $rendered->system);
    }

    public function testBoundPromptWithoutNameRendersAskForNameGuidance(): void
    {
        $rendered = (new PromptRenderer())->render(
            (new OsPersonaPrompt())->withData('Semi', ''),
            [],
            $this->repository(),
        );

        self::assertStringStartsWith(
            "You are Semi, the assistant at the heart of Semitexa OS — a personal, intent-first operating system. You do not know the user's name yet.",
            $rendered->system,
        );
        self::assertStringContainsString('record it with the set-user-name skill', $rendered->system);
    }

    public function testTypedDataMapsToTheTemplateSlots(): void
    {
        $vars = (new OsPersonaPrompt())->withData('Semi', 'Taras')->variables();

        self::assertSame(['assistant_name' => 'Semi', 'user_name' => 'Taras'], $vars);

        // The object's variable keys must cover exactly the template's {{ }} slots.
        $slots = (new PromptRegistry())->buildFromClasses([OsPersonaPrompt::class])['os.persona']->variableNames();
        sort($slots);
        $keys = array_keys($vars);
        sort($keys);
        self::assertSame($slots, $keys);
    }
}
