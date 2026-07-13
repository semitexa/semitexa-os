<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service\Prompt;

use Semitexa\Prompt\Attribute\AsPrompt;
use Semitexa\Prompt\Domain\Contract\FewShotProviderInterface;
use Semitexa\Prompt\Domain\Contract\PromptDefinitionInterface;
use Semitexa\Prompt\Domain\Model\PromptMessage;

/**
 * The Weaver's knowledge-graph extraction prompt. Migrated out of
 * {@see \Semitexa\Os\Application\Service\Weaver::systemPrompt()}.
 *
 * This migration also RESTRUCTURES the few-shot examples: previously they were
 * baked into the system text as an inline "Examples:" block; here they move into
 * proper role-tagged few-shot messages via {@see FewShotProviderInterface}, which
 * the renderer folds into the request history. The instruction text is otherwise
 * preserved. (Behavioural change — smoke-test extraction on the VM.)
 *
 * Bound variables:
 *   - {{ kinds }}         pipe-joined NodeKind values
 *   - {{ projects_line }} known-projects hint (may be empty)
 *   - {{ known_line }}    existing-titles hint (may be empty)
 */
#[AsPrompt(
    id: self::ID,
    channel: 'os',
    description: 'Weaver knowledge-graph extraction prompt (entities + relations as JSON).',
)]
final class KnowledgeGraphExtractionPrompt implements PromptDefinitionInterface, FewShotProviderInterface
{
    public const ID = 'os.weaver.extraction';

    public function system(): string
    {
        return <<<'PROMPT'
        You maintain a personal knowledge graph for the user. From the conversation transcript, extract DURABLE real-world entities in the user's life and work, and the relationships between them.

        Reply with ONLY this JSON, no prose, no code fences:
        {"entities":[{"title":"...","kind":"..."}],"relations":[{"from":"...","to":"...","relation":"..."}]}

        Rules:
        - kind is one of: {{ kinds }}. Pick the closest.
        - Extract only lasting things: people, projects, organisations, places, topics, tasks, events, files. The user is referred to as "self" — use the literal title "self" in relations to link something to the user.
        - Do NOT extract: UI/interface commands (open/close/restyle/retheme the interface, launch apps), styles or moods from those commands, the assistant itself, greetings, questions, opinions, or anything purely hypothetical.
        - Titles: the NAME of the thing only, max 6 words, exactly as the user named it, in the user's language. Never a sentence or description.
        - relation: a short snake_case predicate (works_at, part_of, married_to, located_in, interested_in, owns, works_on, friend_of, ...). Invent one if none fits.
        - Every entity should appear in at least one relation when the transcript supports it.
        - Nothing durable in the transcript? Reply {"entities":[],"relations":[]}.{{ projects_line }}{{ known_line }}
        PROMPT;
    }

    /**
     * The examples that used to live inline under "Examples:" in the system text,
     * now as user (transcript) → assistant (extraction JSON) pairs.
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
