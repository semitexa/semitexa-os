<?php

declare(strict_types=1);

namespace Semitexa\Os\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Os\Application\Service\OsGraph;
use Semitexa\Weave\Domain\Contract\GraphStoreInterface;
use Semitexa\Weave\Domain\Enum\NodeKind;
use Semitexa\Weave\Domain\Model\Node;

/**
 * projects() feeds the Files app's project-first start screen straight from
 * the Weave. Pins the root-resolution precedence (a project's own `path`
 * property beats an attached folder's), the per-kind census, and that a
 * project with no folder still appears (path null) — the world decides what
 * a project is, not the filesystem.
 */
final class OsGraphProjectsTest extends TestCase
{
    #[Test]
    public function a_project_resolves_its_root_from_the_first_attached_folder(): void
    {
        $updatedAt = new \DateTimeImmutable('2026-07-08 10:00:00');
        $project = new Node('p1', NodeKind::Project, 'Aurora', [], '', null, $updatedAt);
        $graph = $this->createMock(GraphStoreInterface::class);
        $graph->method('nodesByKind')->with(NodeKind::Project)->willReturn([$project]);
        $graph->method('neighborhood')->with('p1')->willReturn([
            'node' => $project,
            'edges' => [],
            'neighbors' => [
                new Node('f1', NodeKind::Folder, 'aurora', ['path' => '/home/u/Projects/aurora']),
                new Node('n1', NodeKind::Note, 'Kickoff notes'),
                new Node('t1', NodeKind::Task, 'Ship v1'),
                new Node('t2', NodeKind::Task, 'Write docs'),
            ],
        ]);

        $projects = $this->plant($graph)->projects();

        self::assertCount(1, $projects);
        self::assertSame('Aurora', $projects[0]['title']);
        self::assertSame('/home/u/Projects/aurora', $projects[0]['path']);
        self::assertSame([['title' => 'aurora', 'path' => '/home/u/Projects/aurora']], $projects[0]['folders']);
        self::assertSame(['folder' => 1, 'note' => 1, 'task' => 2], $projects[0]['counts']);
        self::assertSame($updatedAt->format('c'), $projects[0]['updated_at']);
    }

    #[Test]
    public function an_explicit_path_property_on_the_project_wins_over_attachments(): void
    {
        $project = new Node('p1', NodeKind::Project, 'Aurora', ['path' => '/srv/aurora']);
        $graph = $this->createMock(GraphStoreInterface::class);
        $graph->method('nodesByKind')->willReturn([$project]);
        $graph->method('neighborhood')->willReturn([
            'node' => $project,
            'edges' => [],
            'neighbors' => [new Node('f1', NodeKind::Folder, 'elsewhere', ['path' => '/tmp/elsewhere'])],
        ]);

        $projects = $this->plant($graph)->projects();

        self::assertSame('/srv/aurora', $projects[0]['path']);
    }

    #[Test]
    public function a_project_without_any_folder_still_appears_with_a_null_path(): void
    {
        $project = new Node('p1', NodeKind::Project, 'Ideas');
        $graph = $this->createMock(GraphStoreInterface::class);
        $graph->method('nodesByKind')->willReturn([$project]);
        $graph->method('neighborhood')->willReturn(['node' => $project, 'edges' => [], 'neighbors' => []]);

        $projects = $this->plant($graph)->projects();

        self::assertCount(1, $projects);
        self::assertNull($projects[0]['path']);
        self::assertSame([], $projects[0]['folders']);
    }

    #[Test]
    public function the_owner_node_is_not_counted_as_project_content(): void
    {
        $project = new Node('p1', NodeKind::Project, 'Aurora');
        $graph = $this->createMock(GraphStoreInterface::class);
        $graph->method('nodesByKind')->willReturn([$project]);
        $graph->method('neighborhood')->willReturn([
            'node' => $project,
            'edges' => [],
            'neighbors' => [
                new Node('self', NodeKind::Person, 'You', ['is_self' => true]),
                new Node('p2', NodeKind::Person, 'Olena'),
            ],
        ]);

        $projects = $this->plant($graph)->projects();

        self::assertSame(['person' => 1], $projects[0]['counts']);
    }

    #[Test]
    public function a_folder_node_without_a_path_counts_but_cannot_serve_as_root(): void
    {
        $project = new Node('p1', NodeKind::Project, 'Aurora');
        $graph = $this->createMock(GraphStoreInterface::class);
        $graph->method('nodesByKind')->willReturn([$project]);
        $graph->method('neighborhood')->willReturn([
            'node' => $project,
            'edges' => [],
            'neighbors' => [new Node('f1', NodeKind::Folder, 'ghost')],
        ]);

        $projects = $this->plant($graph)->projects();

        self::assertNull($projects[0]['path']);
        self::assertSame(['folder' => 1], $projects[0]['counts']);
    }

    private function plant(GraphStoreInterface $graph): OsGraph
    {
        $osGraph = new OsGraph();
        (new \ReflectionProperty(OsGraph::class, 'graph'))->setValue($osGraph, $graph);

        return $osGraph;
    }
}
