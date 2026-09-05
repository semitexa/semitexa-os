<?php

declare(strict_types=1);

namespace Semitexa\Os\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Support\CoroutineLocal;
use Semitexa\Os\Application\Service\OsGraph;
use Semitexa\Weave\Domain\Contract\GraphStoreInterface;
use Semitexa\Weave\Domain\Enum\NodeKind;
use Semitexa\Weave\Domain\Model\Edge;
use Semitexa\Weave\Domain\Model\Node;

/**
 * What the assistant is actually handed when it reaches for the user's world.
 *
 * The graph's whole value is in the edges — that Дмитро teaches them, that the
 * cat is a pet, that the guitar is an interest. Reading them back as a list of
 * titles hands over a pile of nouns and loses every one of those facts, which
 * is what this pins against.
 */
final class OsGraphRecallTest extends TestCase
{
    private const SELF_ID = 'n-self';

    /**
     * The briefing is memoized per request. Outside a coroutine that store is
     * process-wide, so one test's briefing would otherwise be handed to the
     * next — and the empty-world and broken-graph cases would pass on a value
     * they never produced.
     */
    protected function setUp(): void
    {
        CoroutineLocal::remove('os.graph.briefing');
    }

    #[Test]
    public function recall_says_how_each_thing_relates_not_merely_that_it_exists(): void
    {
        $out = $this->graph()->recall();

        self::assertStringContainsString('Дмитро teaches you', $out);
        self::assertStringContainsString('Мурчик pet_of you', $out);
        self::assertStringContainsString('гітара interested_in you', $out);
    }

    #[Test]
    public function the_owner_is_called_you_rather_than_by_their_stored_title(): void
    {
        $out = $this->graph()->recall();

        self::assertStringNotContainsString('Тарас', $out, 'the model is talking TO them, not about a third party');
    }

    /**
     * The weaver records both directions for the same pair, so any attempt to
     * phrase an edge as a sentence would confidently state the reverse of what
     * is stored. The triple is printed as held.
     */
    #[Test]
    public function a_relation_is_printed_in_the_direction_it_was_stored(): void
    {
        $out = $this->graph()->recall();

        self::assertStringContainsString('гітара interested_in you', $out);
        self::assertStringNotContainsString('you interested_in гітара', $out);
    }

    #[Test]
    public function a_focused_recall_returns_the_things_connections_not_just_itself(): void
    {
        $out = $this->graph()->recall('гітара');

        self::assertStringContainsString('гітара (topic)', $out);
        self::assertStringContainsString('interested_in', $out, 'a hit that repeats the search term teaches nothing');
    }

    #[Test]
    public function an_unknown_term_says_so(): void
    {
        self::assertStringContainsString(
            'anything about "квантова фізика"',
            $this->graph()->recall('квантова фізика'),
        );
    }

    /**
     * Rebuilt once per request, not once per call: the persona is reassembled
     * on every step of the skill loop (up to five on a tool-calling backend),
     * so an unmemoized briefing would repeat its queries five times a turn.
     */
    #[Test]
    public function the_briefing_is_built_once_and_reused_within_a_request(): void
    {
        $graph = $this->graph();
        $first = $graph->worldBriefing();

        // A second call must not reach the store again — proven by swapping in
        // a store that would throw if it were asked.
        (new \ReflectionProperty(OsGraph::class, 'graph'))->setValue($graph, $this->explodingStore());

        self::assertSame($first, $graph->worldBriefing());
    }

    /**
     * TenantFanoutInterface::eachTenant() walks every tenant inside ONE
     * coroutine — the weave timer already does exactly that — so a memo keyed
     * only by coroutine would serve the first tenant's private briefing to the
     * second the moment anything builds a persona under a fan-out.
     */
    #[Test]
    public function the_memo_never_hands_one_tenants_briefing_to_another(): void
    {
        $graph = $this->graph();
        $first = $graph->worldBriefing();
        self::assertStringContainsString('Дмитро teaches you', $first);

        // Same coroutine, different tenant, different world.
        (new \ReflectionProperty(OsGraph::class, 'tenantContextStore'))->setValue($graph, $this->tenantStore('acme'));
        (new \ReflectionProperty(OsGraph::class, 'graph'))->setValue($graph, $this->explodingStore());

        self::assertSame('', $graph->worldBriefing(), 'the other tenant must not inherit this one\'s memo');
    }

    #[Test]
    public function the_standing_briefing_carries_the_same_relations(): void
    {
        $briefing = $this->graph()->worldBriefing();

        self::assertStringContainsString('Дмитро teaches you', $briefing);
        self::assertStringNotContainsString("Here's what", $briefing, 'the briefing is context, not a reply');
    }

    #[Test]
    public function an_empty_world_produces_no_briefing_at_all(): void
    {
        // Rendering an empty block would tell the model it remembers nothing,
        // which is worse than saying nothing.
        self::assertSame('', $this->graph([], [])->worldBriefing());
    }

    /**
     * The briefing rides on every turn. A missing table or a DB hiccup must
     * cost the assistant its memory, never its persona.
     */
    #[Test]
    public function a_broken_graph_costs_the_memory_not_the_persona(): void
    {
        $graph = new OsGraph();
        (new \ReflectionProperty(OsGraph::class, 'graph'))->setValue($graph, $this->explodingStore());

        self::assertSame('', $graph->worldBriefing());
    }

    /** A tenant context pinned to one id, for the fan-out memo test. */
    private function tenantStore(string $tenantId): \Semitexa\Core\Tenant\TenantContextStoreInterface
    {
        return new class($tenantId) implements \Semitexa\Core\Tenant\TenantContextStoreInterface {
            public function __construct(private string $tenantId)
            {
            }

            public function tryGet(): ?\Semitexa\Core\Tenant\TenantContextInterface
            {
                // TenantContextAccess reads the id off getTenantId() when the
                // context exposes one, ahead of the layer lookup.
                return new class($this->tenantId) implements \Semitexa\Core\Tenant\TenantContextInterface {
                    public function __construct(private string $tenantId)
                    {
                    }

                    public function getTenantId(): string
                    {
                        return $this->tenantId;
                    }

                    public function getLayer(\Semitexa\Core\Tenant\Layer\TenantLayerInterface $layer): ?\Semitexa\Core\Tenant\Layer\TenantLayerValueInterface
                    {
                        return null;
                    }

                    public function hasLayer(\Semitexa\Core\Tenant\Layer\TenantLayerInterface $layer): bool
                    {
                        return false;
                    }
                };
            }

            public function get(): \Semitexa\Core\Tenant\TenantContextInterface
            {
                return $this->tryGet();
            }

            public function set(\Semitexa\Core\Tenant\TenantContextInterface $context): void
            {
            }

            public function clear(): void
            {
            }
        };
    }

    /** A store that fails on any question — a missing table, a dead connection. */
    private function explodingStore(): GraphStoreInterface
    {
        return new class implements GraphStoreInterface {
            public function upsertNode(NodeKind $kind, string $title, array $properties = [], string $source = ''): Node { return $this->boom(); }
            public function upsertNodeByRef(NodeKind $kind, string $ref, string $title, array $properties = [], string $source = ''): Node { return $this->boom(); }
            public function nodeByRef(string $ref): ?Node { return $this->boom(); }
            public function addEdge(string $fromId, string $toId, string $relation, int $weight = 100, string $source = ''): Edge { return $this->boom(); }
            public function updateNode(string $id, ?string $title = null, array $properties = []): ?Node { return $this->boom(); }
            public function node(string $id): ?Node { return $this->boom(); }
            public function nodesByKind(NodeKind $kind, int $limit = 0): array { return $this->boom(); }
            public function search(string $term, int $limit = 20): array { return $this->boom(); }
            public function neighborhood(string $nodeId): array { return $this->boom(); }
            public function subgraph(string $nodeId, int $depth = 1): array { return $this->boom(); }
            public function graph(int $limit = 500, ?array $kinds = null): array { return $this->boom(); }
            public function mergeNodes(string $keepId, string $dropId): void { $this->boom(); }
            public function removeNode(string $id): void { $this->boom(); }
            public function removeEdge(string $id): void { $this->boom(); }
            public function counts(): array { return $this->boom(); }
            private function boom(): never { throw new \RuntimeException('Table weave_node does not exist'); }
        };
    }

    /**
     * @param list<Node>|null $neighbors
     * @param list<Edge>|null $edges
     */
    private function graph(?array $neighbors = null, ?array $edges = null): OsGraph
    {
        $self = new Node(self::SELF_ID, NodeKind::Person, 'Тарас', ['is_self' => true]);

        $neighbors ??= [
            new Node('n-dmytro', NodeKind::Person, 'Дмитро'),
            new Node('n-cat', NodeKind::Person, 'Мурчик'),
            new Node('n-guitar', NodeKind::Topic, 'гітара'),
        ];
        $edges ??= [
            new Edge('e1', 'n-dmytro', self::SELF_ID, 'teaches'),
            new Edge('e2', 'n-cat', self::SELF_ID, 'pet_of'),
            new Edge('e3', 'n-guitar', self::SELF_ID, 'interested_in'),
        ];

        $graph = new OsGraph();
        (new \ReflectionProperty(OsGraph::class, 'graph'))->setValue($graph, $this->store($self, $neighbors, $edges));

        return $graph;
    }

    /**
     * @param list<Node> $neighbors
     * @param list<Edge> $edges
     */
    private function store(Node $self, array $neighbors, array $edges): GraphStoreInterface
    {
        return new class($self, $neighbors, $edges) implements GraphStoreInterface {
            /**
             * @param list<Node> $neighbors
             * @param list<Edge> $edges
             */
            public function __construct(
                private Node $self,
                private array $neighbors,
                private array $edges,
            ) {}

            public function node(string $id): ?Node
            {
                foreach (array_merge([$this->self], $this->neighbors) as $n) {
                    if ($n->id === $id) {
                        return $n;
                    }
                }

                return null;
            }

            public function nodesByKind(NodeKind $kind, int $limit = 0): array
            {
                return $kind === NodeKind::Person ? [$this->self] : [];
            }

            public function search(string $term, int $limit = 20): array
            {
                return array_values(array_filter(
                    $this->neighbors,
                    static fn (Node $n): bool => mb_stripos($n->title, $term) !== false,
                ));
            }

            public function neighborhood(string $nodeId): array
            {
                if ($nodeId === $this->self->id) {
                    return ['node' => $this->self, 'edges' => $this->edges, 'neighbors' => $this->neighbors];
                }

                $edges = array_values(array_filter(
                    $this->edges,
                    static fn (Edge $e): bool => $e->fromId === $nodeId || $e->toId === $nodeId,
                ));

                return ['node' => $this->node($nodeId), 'edges' => $edges, 'neighbors' => [$this->self]];
            }

            public function upsertNode(NodeKind $kind, string $title, array $properties = [], string $source = ''): Node
            {
                return $this->self;
            }

            public function upsertNodeByRef(NodeKind $kind, string $ref, string $title, array $properties = [], string $source = ''): Node
            {
                return $this->self;
            }

            public function nodeByRef(string $ref): ?Node
            {
                return null;
            }

            public function addEdge(string $fromId, string $toId, string $relation, int $weight = 100, string $source = ''): Edge
            {
                return new Edge('e-new', $fromId, $toId, $relation);
            }

            public function updateNode(string $id, ?string $title = null, array $properties = []): ?Node
            {
                return $this->node($id);
            }

            public function subgraph(string $nodeId, int $depth = 1): array
            {
                return $this->neighborhood($nodeId);
            }

            public function graph(int $limit = 500, ?array $kinds = null): array
            {
                return ['nodes' => array_merge([$this->self], $this->neighbors), 'edges' => $this->edges];
            }

            public function mergeNodes(string $keepId, string $dropId): void {}

            public function removeNode(string $id): void {}

            public function removeEdge(string $id): void {}

            public function counts(): array
            {
                return ['nodes' => count($this->neighbors) + 1, 'edges' => count($this->edges)];
            }
        };
    }
}
