<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Prompt;

use Semitexa\Prompt\Attribute\AsPrompt;
use Semitexa\Prompt\Domain\Contract\FewShotProviderInterface;
use Semitexa\Prompt\Domain\Model\PromptMessage;

/**
 * The Weaver's knowledge-graph extraction prompt. The instruction body lives in
 * resources/prompts/os.weaver.extraction.twig; the few-shot examples (typed
 * transcript → extraction-JSON pairs) stay in PHP for type safety.
 */
#[AsPrompt(
    id: self::ID,
    channel: 'os',
    description: 'Weaver knowledge-graph extraction prompt (entities + relations as JSON).',
)]
final class KnowledgeGraphExtractionPrompt implements FewShotProviderInterface
{
    public const ID = 'os.weaver.extraction';

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
        ];
    }
}
