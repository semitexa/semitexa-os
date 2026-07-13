<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Llm\Application\Service\LlmProviderResolver;
use Semitexa\Llm\Application\Service\RemoteOllamaProvider;
use Semitexa\Llm\Domain\Contract\LlmProviderInterface;
use Semitexa\Llm\Domain\Model\LlmRequest;
use Semitexa\Os\Application\Service\Prompt\KnowledgeGraphExtractionPrompt;
use Semitexa\Platform\Settings\Application\Service\SettingsStore;
use Semitexa\Prompt\Application\Service\PromptRegistry;
use Semitexa\Prompt\Application\Service\PromptRenderer;
use Semitexa\Prompt\Domain\Model\PromptMessage;
use Semitexa\Prompt\Domain\Model\PromptTemplate;
use Semitexa\Platform\Settings\Domain\Contract\SettingsStoreInterface;
use Semitexa\Weave\Application\Service\GraphStore;
use Semitexa\Weave\Domain\Contract\GraphStoreInterface;
use Semitexa\Weave\Domain\Enum\NodeKind;
use Semitexa\Weave\Domain\Model\Node;
use Semitexa\Weave\Domain\Model\Relation;

/**
 * The weaver — turns conversation into graph. This is the piece that makes the
 * Weave *automatic*: the user never files anything; the weaver reads what was
 * said and quietly connects the entities it finds (people, projects, places,
 * topics…) into the knowledge graph the Workspace view renders.
 *
 * Design: BATCHED, never inline. A reply to the user must never wait for
 * extraction, and the single-slot CPU LLM must never be contended mid-exchange.
 * So the weaver consumes the durable transcript ({@see ConversationStore}) in
 * ordered batches behind a UUIDv7 cursor (settings store, module 'os'), invoked
 * by a background timer ({@see Server\WeaveTimerListener}) or `os:weave`. An
 * idle gate (newest unwoven turn must be a little old) means we weave settled
 * conversation, not the exchange currently in flight.
 *
 * Cursor semantics: advanced when a batch completes an LLM pass — including a
 * pass whose output failed to parse (retrying a poison batch forever would
 * wedge the weaver; the transcript keeps the turns, a future re-weave can
 * revisit). NOT advanced when the provider is unreachable, so nothing is lost
 * to an LLM outage. Graph writes are idempotent (GraphStore dedups nodes by
 * (kind, title) and edges by (from, to, relation)), so re-weaving is safe.
 */
#[AsService]
final class Weaver
{
    private const MODULE = 'os';
    private const CURSOR_KEY = 'weave.cursor';

    /** Max transcript turns consumed per pass — keeps the prompt small on a slow CPU model. */
    private const MAX_TURNS = 12;

    /** Max entities accepted from one pass — a runaway extraction can't flood the graph. */
    private const MAX_ENTITIES = 10;

    /** The newest unwoven turn must be at least this old — weave settled talk, not a live exchange. */
    private const IDLE_SECONDS = 75;

    /** Everything the weaver writes into the GRAPH carries this source tag. */
    private const SOURCE = 'os:weaver';

    /** Transcript-meta source tag on narration turns — the batch guard keys on it. */
    private const SOURCE_META = 'weaver';

    /**
     * Canonical env-aware selector (honours `LLM_BACKEND`) — same reasoning as
     * {@see SkillLoopRunner::$providers}.
     */
    #[InjectAsReadonly]
    protected LlmProviderResolver $providers;

    #[InjectAsReadonly]
    protected ConversationStore $conversation;

    #[InjectAsReadonly]
    protected GraphStoreInterface $graph;

    #[InjectAsReadonly]
    protected OsGraph $osGraph;

    #[InjectAsReadonly]
    protected SettingsStoreInterface $settings;

    /**
     * A pass in flight. Timer::tick fires every 60s REGARDLESS of whether the
     * previous callback finished — and a weave pass can easily outlast the
     * interval (its LLM call queues behind whatever holds the single-slot CPU
     * model, e.g. a cold planner prefill). Overlapping passes all read the
     * cursor BEFORE any of them advances it, then weave the same batch and
     * narrate it repeatedly (observed live: identical narrations 6s apart).
     * The guard collapses the pile-up to one pass; skipped ticks retry later.
     */
    private bool $weaving = false;

    private ?PromptRenderer $renderer = null;
    private ?PromptTemplate $extractionTemplate = null;

    /**
     * One weave pass. Cheap when idle: no unwoven turns (or not yet settled)
     * means no LLM call at all. Never throws — this runs on a timer.
     *
     * @return array{status: string, turns: int, nodes: int, edges: int, detail: list<string>}
     */
    public function tick(): array
    {
        try {
            return $this->weave();
        } catch (\Throwable $e) {
            return ['status' => 'error: ' . $e->getMessage(), 'turns' => 0, 'nodes' => 0, 'edges' => 0, 'detail' => []];
        }
    }

    /** @return array{status: string, turns: int, nodes: int, edges: int, detail: list<string>} */
    public function weave(bool $ignoreIdleGate = false): array
    {
        $none = static fn (string $status): array => ['status' => $status, 'turns' => 0, 'nodes' => 0, 'edges' => 0, 'detail' => []];

        if ($this->weaving) {
            return $none('busy'); // a pass is already in flight — see the guard's docblock
        }
        $this->weaving = true;

        try {
            return $this->weavePass($ignoreIdleGate, $none);
        } finally {
            $this->weaving = false;
        }
    }

    /**
     * @param \Closure(string): array{status: string, turns: int, nodes: int, edges: int, detail: list<string>} $none
     * @return array{status: string, turns: int, nodes: int, edges: int, detail: list<string>}
     */
    private function weavePass(bool $ignoreIdleGate, \Closure $none): array
    {
        $cursor = $this->cursor();
        $turns = $this->conversationStore()->turnsAfter($cursor, self::MAX_TURNS);
        if ($turns === []) {
            return $none('idle');
        }

        // Feedback-loop guard: the weaver's own narration lands in the same
        // transcript it consumes. Weave everything else, but when a batch is
        // narration-only just advance past it — no LLM pass over our own words.
        $substantive = array_values(array_filter(
            $turns,
            static fn (array $t): bool => ($t['meta']['source'] ?? '') !== self::SOURCE_META,
        ));
        if ($substantive === []) {
            $this->setCursor($turns[array_key_last($turns)]['id']);

            return $none('idle');
        }

        if (!$ignoreIdleGate && count($turns) < self::MAX_TURNS && $this->isConversationLive($turns)) {
            return $none('settling'); // mid-exchange — let it finish, weave next pass
        }

        $provider = $this->provider();
        if (!$provider->healthCheck()) {
            return $none('llm-unreachable'); // cursor untouched, unclaimed — the batch retries later
        }

        // Cross-worker single-runner: every worker's 60s timer sees the same
        // batch and, without this, all of them run the LLM extraction and
        // narrate the same result (observed live: identical narrations 6s
        // apart — the per-worker $weaving bool can't serialise across
        // processes). Atomically claim the cursor advance BEFORE the LLM call,
        // so exactly one worker proceeds and the rest bail. Claimed only after
        // the health check passed, so the common outage still leaves the
        // cursor untouched (retry preserved); a claim followed by an LLM
        // failure rolls the cursor back below.
        $batchEnd = $turns[array_key_last($turns)]['id'];
        if (!$this->settings()->claim(self::MODULE, self::CURSOR_KEY, $cursor, $batchEnd)) {
            return $none('busy'); // another worker claimed this batch
        }

        $response = $provider->complete($this->extractionRequest($substantive));
        if (!$response->success) {
            $this->setCursor($cursor); // roll back so the batch retries later
            return $none('llm-failed: ' . (string) $response->error);
        }
        // The claim already advanced the cursor past this batch (kept advanced
        // whatever the parse outcome — see class docblock).

        $parsed = $this->parse($response->content);
        if ($parsed === null) {
            return $none('unparseable');
        }

        [$nodes, $edges, $detail] = $this->apply($parsed);
        $this->narrate($detail);

        return ['status' => 'woven', 'turns' => count($substantive), 'nodes' => $nodes, 'edges' => $edges, 'detail' => $detail];
    }

    /**
     * Make the weaving visible: when a pass actually connected something, the
     * assistant says so first — a proactive turn the shell's /os/proactive poll
     * surfaces (same channel task auto-completion uses) — instead of growing the
     * graph silently. Only relation-bearing passes speak; orphan grounding alone
     * stays quiet (low signal). Best-effort: narration must never fail a pass.
     *
     * @param list<string> $detail
     */
    private function narrate(array $detail): void
    {
        if ($detail === []) {
            return;
        }

        try {
            $shown = array_slice($detail, 0, 3);
            $more = count($detail) - count($shown);
            $this->conversationStore()->append(
                ConversationStore::ROLE_ASSISTANT,
                'I noticed and wove into your world: ' . implode('; ', $shown)
                    . ($more > 0 ? '; and ' . $more . ' more' : '')
                    . '. Open Workspace to see the connections.',
                ['proactive' => true, 'source' => self::SOURCE_META, 'kind' => 'woven', 'skill' => 'weaver'],
            );
        } catch (\Throwable) {
            // narration is a nicety — the weave itself already succeeded
        }
    }

    /** @param list<array{id: string, at: string, role: string, text: string, meta: array<string, mixed>}> $turns */
    private function isConversationLive(array $turns): bool
    {
        $newestAt = strtotime($turns[array_key_last($turns)]['at']) ?: 0;

        return (time() - $newestAt) < self::IDLE_SECONDS;
    }

    /** @param list<array{id: string, at: string, role: string, text: string, meta: array<string, mixed>}> $turns */
    private function extractionRequest(array $turns): LlmRequest
    {
        $lines = [];
        foreach ($turns as $turn) {
            $lines[] = $turn['role'] . ': ' . mb_substr($turn['text'], 0, 400);
        }

        $rendered = $this->renderer()->renderTemplate($this->extractionTemplate(), [
            'kinds' => implode('|', array_map(static fn (NodeKind $k): string => $k->value, NodeKind::cases())),
            'projects_line' => $this->projectsLine(),
            'known_line' => $this->knownLine(),
        ]);

        return new LlmRequest(
            systemPrompt: $rendered->system,
            userMessage: "Transcript:\n" . implode("\n", $lines),
            // The extraction examples now travel as role-tagged few-shot messages
            // (see KnowledgeGraphExtractionPrompt) instead of an inline block.
            history: array_map(
                static fn (PromptMessage $m): array => $m->toArray(),
                $rendered->messages,
            ),
        );
    }

    /**
     * Known-projects hint. Projects are the graph's gravitational centres: when
     * the model knows which projects exist, new work-items land under them, not
     * under "self". Empty when the graph has no projects.
     */
    private function projectsLine(): string
    {
        $projects = array_map(
            static fn ($n): string => $n->title,
            $this->graphStore()->nodesByKind(NodeKind::Project, 12),
        );

        return $projects === [] ? '' : "\n- Known projects: " . implode(', ', $projects)
            . '. When something clearly belongs to one of these, relate it to that project (works_on, part_of, …) instead of "self".';
    }

    /**
     * Existing-titles hint for canonical-title reuse: the model minting a VARIANT
     * of an existing title ("documentation for Semitexa" vs "Semitexa
     * documentation") creates a near-duplicate node. Show it what already exists
     * so it reuses titles. Empty when the graph is empty.
     */
    private function knownLine(): string
    {
        $known = array_map(static fn ($n): string => $n->title, $this->graphStore()->graph(30)['nodes']);

        return $known === [] ? '' : "\n- Titles already in the graph: " . implode('; ', $known)
            . '. When the transcript refers to one of these, use its EXACT title — never a rephrasing.';
    }

    private function renderer(): PromptRenderer
    {
        return $this->renderer ??= new PromptRenderer();
    }

    private function extractionTemplate(): PromptTemplate
    {
        return $this->extractionTemplate ??= (new PromptRegistry())
            ->buildFromClasses([KnowledgeGraphExtractionPrompt::class])[KnowledgeGraphExtractionPrompt::ID];
    }

    /**
     * Parse the model's reply into the extraction shape, tolerating code fences
     * and stray prose around the JSON object.
     *
     * @return array{entities: list<array{title: string, kind: string}>, relations: list<array{from: string, to: string, relation: string}>}|null
     */
    private function parse(string $reply): ?array
    {
        $start = strpos($reply, '{');
        $end = strrpos($reply, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        $decoded = json_decode(substr($reply, $start, $end - $start + 1), true);
        if (!is_array($decoded)) {
            return null;
        }

        $entities = [];
        foreach (is_array($decoded['entities'] ?? null) ? $decoded['entities'] : [] as $e) {
            $title = trim((string) ($e['title'] ?? ''));
            // A sentence is not a node title — dropping beats truncating (a cut-off
            // phrase would still be a bad node AND dedup-poison future upserts).
            $isSentence = count(preg_split('/\s+/u', $title, -1, PREG_SPLIT_NO_EMPTY) ?: []) > 8;
            if ($title === '' || mb_strlen($title) > 80 || $isSentence || strtolower($title) === 'self') {
                continue;
            }
            $entities[] = ['title' => $title, 'kind' => trim((string) ($e['kind'] ?? ''))];
            if (count($entities) >= self::MAX_ENTITIES) {
                break;
            }
        }

        $relations = [];
        foreach (is_array($decoded['relations'] ?? null) ? $decoded['relations'] : [] as $r) {
            $from = trim((string) ($r['from'] ?? ''));
            $to = trim((string) ($r['to'] ?? ''));
            $relation = trim((string) ($r['relation'] ?? ''));
            if ($from === '' || $to === '' || $relation === '' || $from === $to) {
                continue;
            }
            $relations[] = ['from' => $from, 'to' => $to, 'relation' => $relation];
        }

        return ['entities' => $entities, 'relations' => $relations];
    }

    /**
     * Write the extraction into the graph: upsert every entity, resolve relation
     * endpoints (batch titles first, then graph search, "self" = the owner), and
     * connect any orphan entity to the owner so the world stays one constellation.
     *
     * @param array{entities: list<array{title: string, kind: string}>, relations: list<array{from: string, to: string, relation: string}>} $parsed
     * @return array{0: int, 1: int, 2: list<string>}
     */
    private function apply(array $parsed): array
    {
        /** @var array<string, Node> $byTitle */
        $byTitle = [];
        $detail = [];

        foreach ($parsed['entities'] as $entity) {
            $kind = NodeKind::tryFromLoose($entity['kind']) ?? NodeKind::Topic;
            $node = $this->graphStore()->upsertNode($kind, $entity['title'], [], self::SOURCE);
            $byTitle[$this->key($entity['title'])] = $node;
        }

        $linked = [];
        $edges = 0;
        foreach ($parsed['relations'] as $relation) {
            $from = $this->resolve($relation['from'], $byTitle);
            $to = $this->resolve($relation['to'], $byTitle);
            if ($from === null || $to === null || $from->id === $to->id) {
                continue;
            }
            $this->graphStore()->addEdge($from->id, $to->id, Relation::normalise($relation['relation']), 100, self::SOURCE);
            $edges++;
            $linked[$from->id] = true;
            $linked[$to->id] = true;
            $detail[] = $from->title . ' —' . Relation::normalise($relation['relation']) . '→ ' . $to->title;
        }

        // Orphans hang off the owner, mirroring WeaveRememberSkill's grounding.
        $self = $this->osGraph()->self();
        foreach ($byTitle as $node) {
            if (!isset($linked[$node->id]) && $node->id !== $self->id) {
                $this->graphStore()->addEdge($self->id, $node->id, Relation::PART_OF, 100, self::SOURCE);
                $edges++;
            }
        }

        return [count($byTitle), $edges, $detail];
    }

    /** "self" (or the owner's actual name) → the owner node; batch title → its node; else best graph match. */
    private function resolve(string $title, array $byTitle): ?Node
    {
        if (strtolower($title) === 'self' || $this->key($title) === $this->key($this->osGraph()->self()->title)) {
            return $this->osGraph()->self();
        }
        if (isset($byTitle[$this->key($title)])) {
            return $byTitle[$this->key($title)];
        }
        $found = $this->graphStore()->search($title, 1);

        return $found[0] ?? null;
    }

    private function key(string $title): string
    {
        return mb_strtolower(trim($title));
    }

    /**
     * Extraction wants determinism and brevity: reasoning trace off, output
     * capped — same tuning rationale as the planner's provider.
     */
    private function provider(): LlmProviderInterface
    {
        $provider = $this->providers()->provider();
        if ($provider instanceof RemoteOllamaProvider) {
            return $provider->withLimits(160, 0, maxTokens: 500, thinking: false);
        }

        return $provider;
    }

    public function cursor(): string
    {
        $value = $this->settings()->get(self::MODULE, self::CURSOR_KEY);

        return is_string($value) ? $value : '';
    }

    private function setCursor(string $id): void
    {
        $this->settings()->set(self::MODULE, self::CURSOR_KEY, $id);
    }

    /** Start over from the beginning of the transcript (writes stay idempotent). */
    public function resetCursor(): void
    {
        $this->setCursor('');
    }

    // Lazy fallbacks for the storage legs (OsGraph/SkinStore convention). The
    // LLM leg has NO fallback: LlmProviderResolver only works container-managed,
    // so Weaver must be resolved via DI — its callers (the timer listener, the
    // os:weave command) all are.

    private function providers(): LlmProviderResolver
    {
        return $this->providers;
    }

    private function conversationStore(): ConversationStore
    {
        return $this->conversation ??= new ConversationStore();
    }

    private function osGraph(): OsGraph
    {
        return $this->osGraph ??= new OsGraph();
    }

    private function graphStore(): GraphStoreInterface
    {
        return $this->graph ??= new GraphStore();
    }

    private function settings(): SettingsStoreInterface
    {
        return $this->settings ??= new SettingsStore();
    }
}
