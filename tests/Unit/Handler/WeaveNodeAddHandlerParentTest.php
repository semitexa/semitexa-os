<?php

declare(strict_types=1);

namespace Semitexa\Os\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Tenant\Layer\TenantLayerInterface;
use Semitexa\Core\Tenant\Layer\TenantLayerValueInterface;
use Semitexa\Core\Tenant\TenantContextInterface;
use Semitexa\Core\Tenant\TenantContextStoreInterface;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\Os\Application\Handler\PayloadHandler\WeaveNodeAddHandler;
use Semitexa\Os\Application\Payload\Request\WeaveNodeAddPayload;
use Semitexa\Weave\Application\Service\GraphStore;
use Semitexa\Weave\Domain\Enum\NodeKind;
use Semitexa\Weave\Domain\Exception\UnknownNodeException;

/**
 * Adding a node under a parent writes two rows: the node, then its edge. The
 * edge needs both ends in the current tenant's graph, so a parent that is
 * missing or another tenant's must be refused before the node is written:
 * otherwise the client gets an error and the node stays behind, linked to
 * nothing.
 */
final class WeaveNodeAddHandlerParentTest extends TestCase
{
    private OrmManager $orm;

    /** The tenant the store and the handler act as. */
    private string $tenantId = 'acme';

    protected function setUp(): void
    {
        $this->orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        $db = $this->orm->getAdapter();
        $db->execute(
            'CREATE TABLE weave_node (
                id TEXT PRIMARY KEY, tenant_id TEXT, kind TEXT NOT NULL, title TEXT NOT NULL, title_key TEXT NOT NULL,
                token_key TEXT, ext_ref TEXT, properties_json TEXT NOT NULL, source TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL
            )',
        );
        $db->execute('CREATE UNIQUE INDEX uniq_weave_node_kind_title ON weave_node (tenant_id, kind, title_key)');
        $db->execute(
            'CREATE TABLE weave_edge (
                id TEXT PRIMARY KEY, tenant_id TEXT, from_id TEXT NOT NULL, to_id TEXT NOT NULL, relation TEXT NOT NULL,
                weight INTEGER NOT NULL, source TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL
            )',
        );
        $db->execute('CREATE UNIQUE INDEX uniq_weave_edge_triple ON weave_edge (tenant_id, from_id, to_id, relation)');
    }

    #[Test]
    public function an_unknown_parent_is_refused_and_leaves_no_node_behind(): void
    {
        $store = $this->store();

        try {
            $this->add($store, 'Orphan', 'no-such-node');
            self::fail('An unknown parent must be refused.');
        } catch (UnknownNodeException $e) {
            self::assertSame(404, $e->getStatusCode()->value);
        }

        self::assertSame(0, $this->rows('weave_node'), 'Nothing was written for a parent that does not exist.');
        self::assertSame(0, $this->rows('weave_edge'));
    }

    #[Test]
    public function another_tenants_parent_is_refused_like_an_unknown_one_and_leaves_no_node_behind(): void
    {
        $store = $this->store();

        $this->tenantId = 'globex';
        $foreign = $store->upsertNode(NodeKind::Project, 'Globex project')->getId();

        $this->tenantId = 'acme';
        $missingMessage = '';
        try {
            $this->add($store, 'Orphan', 'no-such-node');
        } catch (UnknownNodeException $e) {
            $missingMessage = $e->getMessage();
        }

        try {
            $this->add($store, 'Orphan', $foreign);
            self::fail('A parent in another tenant must be refused.');
        } catch (UnknownNodeException $e) {
            self::assertSame(404, $e->getStatusCode()->value);
            self::assertSame(
                str_replace('no-such-node', $foreign, $missingMessage),
                $e->getMessage(),
                'A foreign parent reads exactly like a missing one.',
            );
        }

        self::assertSame(0, $store->counts()['nodes'], 'Acme holds no node for the refused request.');
        self::assertSame(1, $this->rows('weave_node'), 'Only the Globex node exists, in any tenant.');
        self::assertSame(0, $this->rows('weave_edge'));
    }

    #[Test]
    public function a_parent_of_the_current_tenant_gets_the_node_and_its_edge(): void
    {
        $store = $this->store();
        $parent = $store->upsertNode(NodeKind::Project, 'Semitexa')->getId();

        $body = $this->add($store, 'Release notes', $parent, 'part_of');

        self::assertTrue($body['ok'] ?? null);
        $node = $body['node'] ?? null;
        self::assertIsArray($node);
        $nodeId = $node['id'] ?? null;
        self::assertIsString($nodeId);

        self::assertSame(2, $store->counts()['nodes']);
        $edges = $store->neighborhood($nodeId)['edges'];
        self::assertCount(1, $edges);
        self::assertSame($parent, $edges[0]->getFromId());
        self::assertSame($nodeId, $edges[0]->getToId());
        self::assertSame('part_of', $edges[0]->getRelation());
    }

    #[Test]
    public function no_parent_still_creates_the_node_alone(): void
    {
        $store = $this->store();

        $body = $this->add($store, 'Loose thought', '');

        self::assertTrue($body['ok'] ?? null);
        self::assertSame(['nodes' => 1, 'edges' => 0], $store->counts());
    }

    /**
     * @return array<mixed>
     */
    private function add(GraphStore $store, string $title, string $parentId, string $relation = ''): array
    {
        $payload = new WeaveNodeAddPayload();
        $payload->setTitle($title);
        $payload->setParentId($parentId);
        $payload->setRelation($relation);

        $handler = new WeaveNodeAddHandler();
        (new ReflectionProperty(WeaveNodeAddHandler::class, 'graph'))->setValue($handler, $store);

        $decoded = json_decode($handler->handle($payload, new ResourceResponse())->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function store(): GraphStore
    {
        $store = (new GraphStore())->withTenantContextStore($this->tenants());
        (new ReflectionProperty(GraphStore::class, 'orm'))->setValue($store, $this->orm);

        return $store;
    }

    /** Rows in a table across every tenant: what is really in storage. */
    private function rows(string $table): int
    {
        $rows = $this->orm->getAdapter()->execute('SELECT COUNT(*) AS c FROM ' . $table)->rows;
        $count = $rows[0]['c'] ?? null;
        self::assertIsNumeric($count);

        return (int) $count;
    }

    /** A tenant store that answers with whatever {@see $tenantId} is at call time. */
    private function tenants(): TenantContextStoreInterface
    {
        return new class (fn (): string => $this->tenantId) implements TenantContextStoreInterface {
            /** @param \Closure(): string $tenantId */
            public function __construct(private readonly \Closure $tenantId)
            {
            }

            public function get(): TenantContextInterface
            {
                return new class (($this->tenantId)()) implements TenantContextInterface {
                    public function __construct(private readonly string $tenantId)
                    {
                    }

                    public function getTenantId(): string
                    {
                        return $this->tenantId;
                    }

                    public function getLayer(TenantLayerInterface $layer): ?TenantLayerValueInterface
                    {
                        return null;
                    }

                    public function hasLayer(TenantLayerInterface $layer): bool
                    {
                        return false;
                    }
                };
            }

            public function tryGet(): TenantContextInterface
            {
                return $this->get();
            }

            public function set(TenantContextInterface $context): void
            {
            }

            public function clear(): void
            {
            }
        };
    }
}
