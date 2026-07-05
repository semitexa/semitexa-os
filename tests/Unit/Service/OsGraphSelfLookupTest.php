<?php

declare(strict_types=1);

namespace Semitexa\Os\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Os\Application\Service\OsGraph;
use Semitexa\Platform\Settings\Domain\Contract\SettingsStoreInterface;
use Semitexa\Weave\Domain\Contract\GraphStoreInterface;
use Semitexa\Weave\Domain\Enum\NodeKind;
use Semitexa\Weave\Domain\Model\Node;

/**
 * self() resolves the owner node on nearly every assistant turn. The old
 * implementation scanned EVERY Person node and filtered is_self in PHP each
 * time — unbounded and quadratic as the graph grows. It now caches the owner
 * id in settings and does a single PK lookup, self-healing back to the scan
 * only on first resolution or if the cached node vanished.
 */
final class OsGraphSelfLookupTest extends TestCase
{
    #[Test]
    public function the_cached_id_path_is_a_single_lookup_and_never_scans(): void
    {
        $selfNode = new Node('self-1', NodeKind::Person, 'You', ['is_self' => true]);

        $settings = $this->createMock(SettingsStoreInterface::class);
        $settings->method('get')->with('os', 'graph.self_node_id')->willReturn('self-1');

        $graph = $this->createMock(GraphStoreInterface::class);
        $graph->expects($this->once())->method('node')->with('self-1')->willReturn($selfNode);
        $graph->expects($this->never())->method('nodesByKind'); // the whole point

        $osGraph = $this->plant($graph, $settings);

        self::assertSame($selfNode, $osGraph->self());
    }

    #[Test]
    public function first_resolution_scans_once_then_caches_the_id(): void
    {
        $selfNode = new Node('self-2', NodeKind::Person, 'You', ['is_self' => true]);

        $settings = $this->createMock(SettingsStoreInterface::class);
        $settings->method('get')->willReturn(null); // no cache yet
        $settings->expects($this->once())->method('set')->with('os', 'graph.self_node_id', 'self-2');

        $graph = $this->createMock(GraphStoreInterface::class);
        $graph->expects($this->once())->method('nodesByKind')->with(NodeKind::Person)->willReturn([
            new Node('someone', NodeKind::Person, 'Bob', []),
            $selfNode,
        ]);

        $osGraph = $this->plant($graph, $settings);

        self::assertSame($selfNode, $osGraph->self());
    }

    #[Test]
    public function a_stale_cache_pointing_at_a_vanished_node_heals_via_the_scan(): void
    {
        $selfNode = new Node('self-3', NodeKind::Person, 'You', ['is_self' => true]);

        $settings = $this->createMock(SettingsStoreInterface::class);
        $settings->method('get')->willReturn('gone'); // cached id no longer exists
        $settings->expects($this->once())->method('set')->with('os', 'graph.self_node_id', 'self-3');

        $graph = $this->createMock(GraphStoreInterface::class);
        $graph->method('node')->with('gone')->willReturn(null); // vanished
        $graph->expects($this->once())->method('nodesByKind')->willReturn([$selfNode]);

        $osGraph = $this->plant($graph, $settings);

        self::assertSame($selfNode, $osGraph->self());
    }

    private function plant(GraphStoreInterface $graph, SettingsStoreInterface $settings): OsGraph
    {
        $osGraph = new OsGraph();
        (new \ReflectionProperty(OsGraph::class, 'graph'))->setValue($osGraph, $graph);
        (new \ReflectionProperty(OsGraph::class, 'settings'))->setValue($osGraph, $settings);

        return $osGraph;
    }
}
