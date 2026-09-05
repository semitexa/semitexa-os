<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Prompt;

use Semitexa\Prompt\Attribute\AsPrompt;
use Semitexa\Prompt\Domain\Contract\BoundPromptInterface;
use Semitexa\Prompt\Domain\Contract\FewShotProviderInterface;
use Semitexa\Prompt\Domain\Model\PromptMessage;

/**
 * The Weaver's knowledge-graph extraction prompt. Instruction body in
 * resources/prompts/os.weaver.extraction.twig; the few-shot examples (typed
 * transcript → extraction-JSON pairs) stay in PHP for type safety.
 *
 * Self-binding: carries the node kinds + the known-projects/known-titles lists
 * (bound into the template's `{% if %}` / `{{ …|join }}` hints).
 */
#[AsPrompt(
    id: self::ID,
    channel: 'os',
    template: 'resources/prompts/os.weaver.extraction.twig',
    description: 'Weaver knowledge-graph extraction prompt (entities + relations as JSON).',
)]
final class KnowledgeGraphExtractionPrompt implements BoundPromptInterface, FewShotProviderInterface
{
    public const ID = 'os.weaver.extraction';

    /**
     * @param list<string> $projects
     * @param list<string> $known
     */
    public function __construct(
        private readonly ?string $kinds = null,
        private readonly array $projects = [],
        private readonly array $known = [],
    ) {}

    /**
     * @param list<string> $projects
     * @param list<string> $known
     */
    public function withData(string $kinds, array $projects, array $known): self
    {
        return new self($kinds, $projects, $known);
    }

    public function promptId(): string
    {
        return self::ID;
    }

    public function kinds(): string
    {
        return (string) $this->kinds;
    }

    /** @return list<string> */
    public function projects(): array
    {
        return $this->projects;
    }

    /** @return list<string> */
    public function known(): array
    {
        return $this->known;
    }

    /**
     * The examples that used to live inline under "Examples:" in the system text,
     * as user (transcript) → assistant (extraction JSON) pairs.
     *
     * @return list<PromptMessage>
     */
    public function fewShot(): array
    {
        return [
            PromptMessage::user('user: мій колега Богдан допомагає мені з дизайном Semitexa'),
            PromptMessage::assistant('{"entities":[{"title":"Богдан","kind":"person"},{"title":"Semitexa","kind":"project"}],"relations":[{"from":"Богдан","to":"self","relation":"colleague_of"},{"from":"Богдан","to":"Semitexa","relation":"works_on"}]}'),
            PromptMessage::user('user: restyle the interface like a sunset at sea'),
            PromptMessage::assistant('{"entities":[],"relations":[]}'),
            PromptMessage::user('user: my sister Emma moved to Lisbon'),
            PromptMessage::assistant('{"entities":[{"title":"Emma","kind":"person"},{"title":"Lisbon","kind":"place"}],"relations":[{"from":"Emma","to":"self","relation":"sister_of"},{"from":"Emma","to":"Lisbon","relation":"lives_in"}]}'),
            // A preference and a goal. Without an example of each the model
            // keeps returning only entities, whatever the rules say.
            PromptMessage::user('user: терпіти не можу відеодзвінки, завжди прошу переписку. і хочу за рік переїхати до Португалії'),
            PromptMessage::assistant('{"entities":[{"title":"відеодзвінки","kind":"topic"},{"title":"переїхати до Португалії","kind":"goal"}],"relations":[{"from":"self","to":"відеодзвінки","relation":"avoids"},{"from":"self","to":"переїхати до Португалії","relation":"wants"}]}'),
            PromptMessage::user('user: today is annoying, everything is breaking'),
            PromptMessage::assistant('{"entities":[],"relations":[]}'),
        ];
    }
}
