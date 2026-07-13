<?php

declare(strict_types=1);

namespace Semitexa\Os\Tests\Unit\Prompt;

use PHPUnit\Framework\TestCase;
use Semitexa\Os\Application\Prompt\KnowledgeGraphExtractionPrompt;
use Semitexa\Prompt\Application\Service\PromptRegistry;
use Semitexa\Prompt\Application\Service\PromptRenderer;
use Semitexa\Prompt\Domain\Enum\MessageRole;

/**
 * Exercises FewShotProviderInterface in a real consumer: the Weaver extraction
 * prompt keeps its instruction text but relocates its examples from an inline
 * block into role-tagged few-shot messages, which flow through the registry and
 * renderer into the request.
 */
final class KnowledgeGraphExtractionPromptTest extends TestCase
{
    public function testInstructionTextIsPreservedAndExamplesAreRemovedFromSystem(): void
    {
        $system = rtrim((new PromptRegistry())->buildFromClasses([KnowledgeGraphExtractionPrompt::class])['os.weaver.extraction']->system);

        self::assertStringStartsWith(
            "You maintain a personal knowledge graph for the user. From the conversation transcript, extract DURABLE real-world entities in the user's life and work, and the relationships between them.",
            $system,
        );
        self::assertStringContainsString(
            '{"entities":[{"title":"...","kind":"..."}],"relations":[{"from":"...","to":"...","relation":"..."}]}',
            $system,
        );
        self::assertStringContainsString('- kind is one of: {{ prompt.kinds }}. Pick the closest.', $system);
        // The projects/known hints are native Twig conditionals over the bound object.
        self::assertStringContainsString("{% if prompt.projects %}", $system);
        self::assertStringContainsString("{{ prompt.projects|join(', ') }}", $system);
        self::assertStringContainsString("{% if prompt.known %}", $system);

        // The examples must no longer be in the system text — they are few-shot now.
        self::assertStringNotContainsString('Examples:', $system);
        self::assertStringNotContainsString('Богдан', $system);
    }

    public function testFewShotIsThreeUserAssistantPairs(): void
    {
        $fewShot = (new KnowledgeGraphExtractionPrompt())->fewShot();

        self::assertCount(6, $fewShot);
        self::assertSame(
            [MessageRole::User, MessageRole::Assistant, MessageRole::User, MessageRole::Assistant, MessageRole::User, MessageRole::Assistant],
            array_map(static fn ($m) => $m->role, $fewShot),
        );

        self::assertSame('user: мій колега Богдан допомагає мені з дизайном Semitexa', $fewShot[0]->content);
        self::assertSame(
            '{"entities":[{"title":"Богдан","kind":"person"},{"title":"Semitexa","kind":"project"}],"relations":[{"from":"Богдан","to":"self","relation":"colleague_of"},{"from":"Богдан","to":"Semitexa","relation":"works_on"}]}',
            $fewShot[1]->content,
        );
        self::assertSame('user: restyle the interface like a sunset at sea', $fewShot[2]->content);
        self::assertSame('{"entities":[],"relations":[]}', $fewShot[3]->content);
        self::assertSame('user: my sister Emma moved to Lisbon', $fewShot[4]->content);
    }

    public function testFewShotFlowsThroughRegistryAndRenderer(): void
    {
        $template = (new PromptRegistry())->buildFromClasses([KnowledgeGraphExtractionPrompt::class])['os.weaver.extraction'];
        self::assertCount(6, $template->fewShot);

        $rendered = (new PromptRenderer())->render(
            (new KnowledgeGraphExtractionPrompt())->withData('person|project|place', [], []),
        );

        // Getters bound in system, few-shot carried through untouched.
        self::assertStringContainsString('- kind is one of: person|project|place. Pick the closest.', $rendered->system);
        self::assertStringNotContainsString('{{', $rendered->system);
        self::assertCount(6, $rendered->messages);
        self::assertSame('{"entities":[],"relations":[]}', $rendered->messages[3]->content);
    }

    public function testProjectsAndKnownHintsRenderNativelyFromLists(): void
    {
        $renderer = new PromptRenderer();

        // With no data → no hint lines (the {% if %} branches stay empty).
        $empty = $renderer->render((new KnowledgeGraphExtractionPrompt())->withData('person', [], []));
        self::assertStringNotContainsString('Known projects:', $empty->system);
        self::assertStringNotContainsString('Titles already in the graph:', $empty->system);

        // With data → the Twig loop/join builds the exact hint lines.
        $full = $renderer->render(
            (new KnowledgeGraphExtractionPrompt())->withData('person', ['Semitexa', 'Apart'], ['Bohdan', 'Emma']),
        );
        self::assertStringContainsString("\n- Known projects: Semitexa, Apart. When something clearly belongs", $full->system);
        self::assertStringContainsString("\n- Titles already in the graph: Bohdan; Emma. When the transcript", $full->system);
    }
}
