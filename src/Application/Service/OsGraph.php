<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Support\CoroutineLocal;
use Semitexa\Core\Tenant\TenantContextAccess;
use Semitexa\Core\Tenant\TenantContextStoreInterface;
use Semitexa\Platform\Settings\Application\Service\SettingsStore;
use Semitexa\Platform\Settings\Domain\Contract\SettingsStoreInterface;
use Semitexa\Weave\Application\Service\GraphStore;
use Semitexa\Weave\Domain\Contract\GraphStoreInterface;
use Semitexa\Weave\Domain\Enum\NodeKind;
use Semitexa\Weave\Domain\Model\Edge;
use Semitexa\Weave\Domain\Model\Node;
use Semitexa\Weave\Domain\Model\Relation;

/**
 * The OS's view of the Weave — the seam between the assistant and the pure
 * {@see GraphStore} data layer. This is where OS-specific graph concepts live
 * (the single "self" node = the owner; recording/recalling "your world") so the
 * weave package stays free of any OS/LLM knowledge. Handlers inject it; the
 * assistant's skills ({@see WeaveRememberSkill}, {@see WeaveRecallSkill}) `new`
 * it — hence the lazy fallbacks, same convention as OsPreferences/SkinStore.
 */
#[AsService]
final class OsGraph
{
    private const MODULE = 'os';

    /** How many search hits a focused recall expands with their relations. */
    private const RECALL_HITS = 6;

    /** Relations shown per thing — a hub must not flood the reply. */
    private const RECALL_RELATIONS_PER_NODE = 4;

    /** Entries carried in the standing briefing — this rides on EVERY turn. */
    private const BRIEFING_ENTRIES = 12;

    /** Per-request memo for the briefing — see {@see worldBriefing()}. */
    private const BRIEFING_MEMO_KEY = 'os.graph.briefing';

    /** Settings key caching the owner node's id — see {@see self()}. */
    private const SELF_ID_KEY = 'graph.self_node_id';

    #[InjectAsReadonly]
    protected GraphStoreInterface $graph;

    #[InjectAsReadonly]
    protected OsPreferences $prefs;

    #[InjectAsReadonly]
    protected SettingsStoreInterface $settings;

    /** Only ever read to key the per-request briefing memo — see worldBriefing(). */
    #[InjectAsReadonly]
    protected TenantContextStoreInterface $tenantContextStore;

    /**
     * The owner node ("Я") — the centre of the graph. Created once (a Person
     * flagged is_self, seeded from the user's name) so the world always has a
     * root to hang off.
     */
    public function self(): Node
    {
        // Fast path: the owner node's id is cached in settings, so this is a
        // single PK lookup. self() is called on nearly every assistant turn
        // (remember / recall / attach / weave-anchoring); the old
        // nodesByKind(Person) scan loaded EVERY person and filtered is_self in
        // PHP each time — unbounded and quadratic as the graph grows.
        $cachedId = $this->settings()->get(self::MODULE, self::SELF_ID_KEY);
        if (is_string($cachedId) && $cachedId !== '') {
            $node = $this->graph()->node($cachedId);
            if ($node !== null && ($node->getProperties()['is_self'] ?? false) === true) {
                return $node;
            }
        }

        // Self-healing fallback — first-ever resolution, or the cached node
        // vanished. Scan once, cache the id so every later call is O(1).
        foreach ($this->graph()->nodesByKind(NodeKind::Person) as $node) {
            if (($node->getProperties()['is_self'] ?? false) === true) {
                $this->cacheSelfId($node->getId());

                return $node;
            }
        }

        $name = $this->prefs()->userName();
        $created = $this->graph()->upsertNode(NodeKind::Person, $name !== '' ? $name : 'You', ['is_self' => true], 'os:self');
        $this->cacheSelfId($created->getId());

        return $created;
    }

    private function cacheSelfId(string $id): void
    {
        try {
            $this->settings()->set(self::MODULE, self::SELF_ID_KEY, $id);
        } catch (\Throwable) {
            // Best-effort cache — a settings write failure only costs the next
            // call another scan, never correctness.
        }
    }

    /**
     * Record something in the user's world as a connected node — linked to a
     * named existing thing if given, otherwise to the owner.
     *
     * @return array{node: Node, parent: Node}
     */
    public function remember(string $title, string $kind = '', string $connectTo = ''): array
    {
        $title = trim($title);
        if ($title === '') {
            throw new \InvalidArgumentException('Nothing to record.');
        }
        $nodeKind = $kind !== '' ? (NodeKind::tryFromLoose($kind) ?? NodeKind::Topic) : NodeKind::Topic;
        $node = $this->graph()->upsertNode($nodeKind, $title, [], 'os:assistant');

        $self = $this->self();
        $parent = $self;
        $connectTo = trim($connectTo);
        if ($connectTo !== '') {
            $found = $this->graph()->search($connectTo, 1);
            if (isset($found[0]) && $found[0]->getId() !== $node->getId()) {
                $parent = $found[0];
            }
        }
        if ($parent->getId() !== $node->getId()) {
            // The new thing is the subject, and grounding to the owner is not
            // containment: "Anna part_of you" was both backwards and untrue.
            $parent->getId() === $self->getId()
                ? $this->graph()->addEdge($node->getId(), $parent->getId(), Relation::RELATED_TO, 100, 'os:assistant')
                : $this->graph()->addEdge($node->getId(), $parent->getId(), Relation::PART_OF, 100, 'os:assistant');
        }

        return ['node' => $node, 'parent' => $parent];
    }

    /**
     * Files-as-nodes: attach a real filesystem path to the user's world as a
     * folder/file LEAF node. Linked to the best-matching existing entity — the
     * path's basename (then its stem: "semitexa.dev" → "semitexa") is searched
     * in the graph, preferring non-file entities so a folder lands under its
     * project, not under another folder — else grounded to the owner. Clicking
     * the node in the Workspace opens the Files app right there, so this is the
     * closing of the loop: browse → attach → see in the world → open back.
     * Idempotent: re-attaching the same path refreshes the node's `path`.
     *
     * @return array{node: Node, parent: Node}
     */
    public function attachPath(string $path, string $kind = 'folder', string $connectTo = ''): array
    {
        $path = rtrim(trim($path), '/');
        if ($path === '' || $path[0] !== '/') {
            throw new \InvalidArgumentException('An absolute path is required.');
        }
        $title = basename($path);
        if ($title === '') {
            throw new \InvalidArgumentException('The filesystem root cannot be attached.');
        }

        $nodeKind = $kind === 'file' ? NodeKind::File : NodeKind::Folder;
        $node = $this->graph()->upsertNode($nodeKind, $title, ['path' => $path], 'os:files');

        $parent = $this->resolveAttachParent($node, $title, trim($connectTo));
        if ($parent->getId() !== $node->getId()) {
            // The folder is part of the project, not the project of the folder.
            $parent->getId() === $this->self()->getId()
                ? $this->graph()->addEdge($node->getId(), $parent->getId(), Relation::RELATED_TO, 100, 'os:files')
                : $this->graph()->addEdge($node->getId(), $parent->getId(), Relation::PART_OF, 100, 'os:files');
        }

        return ['node' => $node, 'parent' => $parent];
    }

    /** The entity a path should hang off (see attachPath) — never a file/folder unless named explicitly. */
    private function resolveAttachParent(Node $node, string $title, string $connectTo): Node
    {
        $leafKinds = [NodeKind::File, NodeKind::Folder];

        if ($connectTo !== '') {
            $found = $this->graph()->search($connectTo, 1);
            if (isset($found[0]) && $found[0]->getId() !== $node->getId()) {
                return $found[0];
            }
        }

        $stem = (string) preg_split('/[.\-_ ]+/', $title, 2)[0];
        foreach (array_unique([$title, mb_strlen($stem) >= 3 ? $stem : '']) as $term) {
            if ($term === '') {
                continue;
            }
            foreach ($this->graph()->search($term, 5) as $hit) {
                if ($hit->getId() !== $node->getId() && !in_array($hit->getKind(), $leafKinds, true)) {
                    return $hit;
                }
            }
        }

        return $this->self();
    }

    /**
     * The project-first view the Files app boots from: every Project node with
     * its resolved root folder and a per-kind census of what hangs off it.
     * Projects are the gravitational centre of the Weave (see NodeKind), so
     * this IS the OS's project list — no filesystem scanning involved.
     *
     * Root resolution: the project's own `path` property (an explicit root)
     * wins; else the first attached Folder neighbour that carries a path
     * (attachPath hangs folders off projects with one PART_OF hop); else null
     * — the project exists in the world but has no files yet.
     *
     * @return list<array{id: string, title: string, path: string|null, folders: list<array{title: string, path: string}>, counts: array<string, int>, updated_at: string|null}>
     */
    public function projects(): array
    {
        $projects = [];
        foreach ($this->graph()->nodesByKind(NodeKind::Project) as $project) {
            $folders = [];
            $counts = [];
            foreach ($this->graph()->neighborhood($project->getId())['neighbors'] as $neighbor) {
                if (($neighbor->getProperties()['is_self'] ?? false) === true) {
                    continue; // the owner is the graph's root, not project content
                }
                $counts[$neighbor->getKind()->value] = ($counts[$neighbor->getKind()->value] ?? 0) + 1;
                $path = $neighbor->getProperties()['path'] ?? null;
                if ($neighbor->getKind() === NodeKind::Folder && is_string($path) && $path !== '') {
                    $folders[] = ['title' => $neighbor->getTitle(), 'path' => $path];
                }
            }

            $ownPath = $project->getProperties()['path'] ?? null;
            $projects[] = [
                'id' => $project->getId(),
                'title' => $project->getTitle(),
                'path' => is_string($ownPath) && $ownPath !== '' ? $ownPath : ($folders[0]['path'] ?? null),
                'folders' => $folders,
                'counts' => $counts,
                'updated_at' => $project->getUpdatedAt()?->format('c'),
            ];
        }

        return $projects;
    }

    /**
     * Recall from the graph: matches for a query term, or (no term) a summary of
     * what is connected to the owner. Returns assistant-ready prose.
     */
    public function recall(string $query = ''): string
    {
        $query = trim($query);
        $selfId = $this->self()->getId();

        if ($query !== '') {
            $hits = $this->graph()->search($query, self::RECALL_HITS);
            if ($hits === []) {
                return 'I don\'t have anything about "' . $query . '" in your world yet.';
            }

            $lines = [];
            foreach ($hits as $hit) {
                $view = $this->graph()->neighborhood($hit->getId());
                $lines[] = $this->recallLine($hit, $view['neighbors'] ?? [], $view['edges'] ?? [], $selfId);
            }

            return 'Here\'s what I have about "' . $query . '":' . "\n" . implode("\n", $lines);
        }

        $view = $this->graph()->neighborhood($selfId);
        $neighbors = $view['neighbors'] ?? [];
        if ($neighbors === []) {
            return 'Your world is just getting started — nothing is connected to you yet. Tell me what you\'re working on.';
        }

        $lines = [];
        foreach ($neighbors as $neighbor) {
            $lines[] = $this->recallLine($neighbor, [$this->selfNodeFrom($view)], $view['edges'] ?? [], $selfId, $neighbor->getId());
        }

        return 'Here\'s what\'s in your world right now:' . "\n" . implode("\n", $lines);
    }

    /**
     * The standing briefing about the user, for the assistant's system prompt.
     *
     * recall() answers a question; this answers none — it is what the assistant
     * carries into every turn so it can use what it knows without being asked.
     * The skill alone was never enough: the model had to decide to call it, and
     * nothing in the persona told it the graph existed.
     *
     * Best-effort by construction. An empty graph, a missing table or a DB
     * hiccup must cost the assistant its memory for that turn, never its
     * persona — the caller renders nothing when this returns ''.
     */
    public function worldBriefing(int $limit = self::BRIEFING_ENTRIES): string
    {
        // Memoized per request, not per call. The persona is rebuilt inside the
        // skill loop's step budget — up to five steps on a tool-calling backend
        // — so an unmemoized briefing would repeat its ~5 queries on every step
        // of every turn. CoroutineLocal rather than a property, because a
        // container-managed service is shared across concurrent coroutines.
        //
        // KEYED BY TENANT, even though a request coroutine only ever serves one.
        // TenantFanoutInterface::eachTenant() walks every tenant inside a SINGLE
        // coroutine — that is how the weave timer works — so a bare key would
        // hand one tenant's private briefing to the next the moment anything
        // builds a persona under a fan-out. Same reasoning as the override
        // store's per-tenant memo.
        $tenant = TenantContextAccess::tenantIdOrDefault(
            isset($this->tenantContextStore) ? $this->tenantContextStore->tryGet() : null,
        );

        /** @var array<string, string> $memo */
        $memo = CoroutineLocal::get(self::BRIEFING_MEMO_KEY, []);
        if (isset($memo[$tenant])) {
            return $memo[$tenant];
        }

        $memo[$tenant] = $this->buildBriefing($limit);
        CoroutineLocal::set(self::BRIEFING_MEMO_KEY, $memo);

        return $memo[$tenant];
    }

    private function buildBriefing(int $limit): string
    {
        try {
            $selfId = $this->self()->getId();
            $view = $this->graph()->neighborhood($selfId);
            $neighbors = $view['neighbors'] ?? [];
            if ($neighbors === []) {
                return '';
            }

            $self = $this->selfNodeFrom($view);
            $lines = [];
            foreach (array_slice($neighbors, 0, $limit) as $neighbor) {
                $lines[] = $this->recallLine($neighbor, [$self], $view['edges'] ?? [], $selfId, $neighbor->getId());
            }

            return implode("\n", $lines);
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * One recall line: the thing, and — the part that used to be thrown away —
     * how it actually relates to the rest of the world.
     *
     * A bare list of titles tells the assistant that "Дмитро" and "гітара" exist
     * and nothing else, so it cannot answer that Дмитро teaches the user or that
     * the guitar is an interest. The edges are already in hand from
     * neighborhood(); this renders them.
     *
     * Relations are printed as the stored triple rather than turned into a
     * sentence. The weaver emits both directions for the same pair (measured:
     * "гітара interested_in <user>" alongside "<user> works_on Semitexa"), so any
     * phrasing that assumed a direction would confidently state the reverse of
     * what is recorded. The triple is honest about what the graph holds.
     *
     * @param list<Node> $others  nodes that may sit at the other end of an edge
     * @param list<Edge> $edges   every edge in the view, filtered here
     * @param string     $focusId the node whose edges to render (defaults to $node)
     */
    private function recallLine(Node $node, array $others, array $edges, string $selfId, string $focusId = ''): string
    {
        $focusId = $focusId !== '' ? $focusId : $node->getId();
        $titles = [$node->getId() => $node->getTitle()];
        foreach ($others as $other) {
            $titles[$other->getId()] = $other->getTitle();
        }
        $titles[$selfId] = 'you';

        $rendered = [];
        foreach ($edges as $edge) {
            if ($edge->getFromId() !== $focusId && $edge->getToId() !== $focusId) {
                continue;
            }
            $from = $titles[$edge->getFromId()] ?? null;
            $to = $titles[$edge->getToId()] ?? null;
            if ($from === null || $to === null) {
                continue;
            }
            $rendered[] = $from . ' ' . $edge->getRelation() . ' ' . $to;
            if (count($rendered) >= self::RECALL_RELATIONS_PER_NODE) {
                break;
            }
        }

        $line = '• ' . $node->getTitle() . ' (' . $node->getKind()->value . ')';

        return $rendered === [] ? $line : $line . ' — ' . implode(', ', array_unique($rendered));
    }

    /** The owner node as it appears inside its own neighbourhood view. */
    private function selfNodeFrom(array $view): Node
    {
        $node = $view['node'] ?? null;

        return $node instanceof Node ? $node : $this->self();
    }

    private function graph(): GraphStoreInterface
    {
        return $this->graph ??= new GraphStore();
    }

    private function prefs(): OsPreferences
    {
        return $this->prefs ??= new OsPreferences();
    }

    private function settings(): SettingsStoreInterface
    {
        return $this->settings ??= new SettingsStore();
    }
}
