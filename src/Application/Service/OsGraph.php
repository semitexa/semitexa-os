<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
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
    #[InjectAsReadonly]
    protected GraphStoreInterface $graph;

    #[InjectAsReadonly]
    protected OsPreferences $prefs;

    /**
     * The owner node ("Я") — the centre of the graph. Created once (a Person
     * flagged is_self, seeded from the user's name) so the world always has a
     * root to hang off.
     */
    public function self(): Node
    {
        foreach ($this->graph()->nodesByKind(NodeKind::Person) as $node) {
            if (($node->properties['is_self'] ?? false) === true) {
                return $node;
            }
        }
        $name = $this->prefs()->userName();

        return $this->graph()->upsertNode(NodeKind::Person, $name !== '' ? $name : 'You', ['is_self' => true], 'os:self');
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
}
