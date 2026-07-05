<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Platform\Settings\Application\Service\SettingsStore;
use Semitexa\Platform\Settings\Domain\Contract\SettingsStoreInterface;
use Semitexa\Weave\Application\Service\GraphStore;
use Semitexa\Weave\Domain\Contract\GraphStoreInterface;
use Semitexa\Weave\Domain\Enum\NodeKind;
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

    /** Settings key caching the owner node's id — see {@see self()}. */
    private const SELF_ID_KEY = 'graph.self_node_id';

    #[InjectAsReadonly]
    protected GraphStoreInterface $graph;

    #[InjectAsReadonly]
    protected OsPreferences $prefs;

    #[InjectAsReadonly]
    protected SettingsStoreInterface $settings;

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
            if ($node !== null && ($node->properties['is_self'] ?? false) === true) {
                return $node;
            }
        }

        // Self-healing fallback — first-ever resolution, or the cached node
        // vanished. Scan once, cache the id so every later call is O(1).
        foreach ($this->graph()->nodesByKind(NodeKind::Person) as $node) {
            if (($node->properties['is_self'] ?? false) === true) {
                $this->cacheSelfId($node->id);

                return $node;
            }
        }

        $name = $this->prefs()->userName();
        $created = $this->graph()->upsertNode(NodeKind::Person, $name !== '' ? $name : 'You', ['is_self' => true], 'os:self');
        $this->cacheSelfId($created->id);

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

        $parent = $this->self();
        $connectTo = trim($connectTo);
        if ($connectTo !== '') {
            $found = $this->graph()->search($connectTo, 1);
            if (isset($found[0]) && $found[0]->id !== $node->id) {
                $parent = $found[0];
            }
        }
        if ($parent->id !== $node->id) {
            $this->graph()->addEdge($parent->id, $node->id, Relation::PART_OF, 100, 'os:assistant');
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
        if ($parent->id !== $node->id) {
            $this->graph()->addEdge($parent->id, $node->id, Relation::PART_OF, 100, 'os:files');
        }

        return ['node' => $node, 'parent' => $parent];
    }

    /** The entity a path should hang off (see attachPath) — never a file/folder unless named explicitly. */
    private function resolveAttachParent(Node $node, string $title, string $connectTo): Node
    {
        $leafKinds = [NodeKind::File, NodeKind::Folder];

        if ($connectTo !== '') {
            $found = $this->graph()->search($connectTo, 1);
            if (isset($found[0]) && $found[0]->id !== $node->id) {
                return $found[0];
            }
        }

        $stem = (string) preg_split('/[.\-_ ]+/', $title, 2)[0];
        foreach (array_unique([$title, mb_strlen($stem) >= 3 ? $stem : '']) as $term) {
            if ($term === '') {
                continue;
            }
            foreach ($this->graph()->search($term, 5) as $hit) {
                if ($hit->id !== $node->id && !in_array($hit->kind, $leafKinds, true)) {
                    return $hit;
                }
            }
        }

        return $this->self();
    }

    /**
     * Recall from the graph: matches for a query term, or (no term) a summary of
     * what is connected to the owner. Returns assistant-ready prose.
     */
    public function recall(string $query = ''): string
    {
        $query = trim($query);
        $line = static fn (Node $n): string => '• ' . $n->title . ' (' . $n->kind->value . ')';

        if ($query !== '') {
            $hits = $this->graph()->search($query, 8);
            if ($hits === []) {
                return 'I don\'t have anything about "' . $query . '" in your world yet.';
            }

            return 'Here\'s what I have about "' . $query . '":' . "\n" . implode("\n", array_map($line, $hits));
        }

        $neighbors = $this->graph()->neighborhood($this->self()->id)['neighbors'] ?? [];
        if ($neighbors === []) {
            return 'Your world is just getting started — nothing is connected to you yet. Tell me what you\'re working on.';
        }

        return 'Here\'s what\'s in your world right now:' . "\n" . implode("\n", array_map($line, $neighbors));
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
