<?php

declare(strict_types=1);

namespace Semitexa\Os\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Tenant\Layer\TenantLayerInterface;
use Semitexa\Core\Tenant\Layer\TenantLayerValueInterface;
use Semitexa\Core\Tenant\TenantContextInterface;
use Semitexa\Core\Tenant\TenantContextStoreInterface;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\Os\Application\Service\ConversationStore;
use Swoole\Coroutine;

/**
 * The OS transcript is #[TenantScoped] — the security property this pins:
 * one tenant's OS can never read, count, or clear another tenant's dialog,
 * neither sequentially nor when two tenants drive the same worker-scoped
 * ConversationStore singleton concurrently (coroutine-local tenant context).
 */
final class ConversationStoreTenantIsolationTest extends TestCase
{
    private function freshStore(TenantContextStoreInterface $ctx): ConversationStore
    {
        $orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        $orm->getAdapter()->execute(
            'CREATE TABLE os_conversation_turn (
                id TEXT PRIMARY KEY,
                tenant_id TEXT,
                role TEXT NOT NULL,
                text TEXT NOT NULL,
                meta_json TEXT NOT NULL DEFAULT "[]",
                created_at TEXT NOT NULL
            )',
        );

        return (new ConversationStore())->withOrmManager($orm)->withTenantContextStore($ctx);
    }

    #[Test]
    public function one_tenant_never_reads_or_clears_another_tenants_transcript(): void
    {
        $ctx = new MutableTenantContextStore();
        $store = $this->freshStore($ctx);

        $ctx->switchTo('acme');
        $store->append(ConversationStore::ROLE_USER, 'acme secret intent');
        $store->append(ConversationStore::ROLE_ASSISTANT, 'acme reply');

        // A different tenant on the same store sees NONE of it.
        $ctx->switchTo('globex');
        self::assertSame(0, $store->count(), 'Globex must not count Acme turns.');
        self::assertSame([], $store->turns(), 'Globex must not read Acme turns.');

        $store->append(ConversationStore::ROLE_USER, 'globex intent');
        self::assertCount(1, $store->turns(), 'Globex sees only its own turn.');

        // clear() under Globex must not touch Acme.
        $store->clear();
        self::assertSame(0, $store->count());

        $ctx->switchTo('acme');
        self::assertSame(2, $store->count(), 'Acme transcript survives Globex clear().');
        // Order within the same millisecond is not asserted (UUIDv7 tiebreak);
        // the isolation property is that BOTH Acme turns are intact and no
        // Globex turn leaked in.
        $texts = array_column($store->turns(), 'text');
        sort($texts);
        self::assertSame(['acme reply', 'acme secret intent'], $texts);
    }

    #[Test]
    public function the_default_context_is_its_own_partition(): void
    {
        $ctx = new MutableTenantContextStore(); // tryGet() = null → 'default'
        $store = $this->freshStore($ctx);

        $store->append(ConversationStore::ROLE_USER, 'default intent');
        self::assertSame(1, $store->count());

        $ctx->switchTo('acme');
        self::assertSame(0, $store->count(), 'A named tenant must not see the default partition.');
    }

    #[Test]
    public function concurrent_coroutines_keep_their_own_tenant_transcript(): void
    {
        if (!class_exists(Coroutine::class)) {
            self::markTestSkipped('Swoole not available.');
        }

        // ONE store + the REAL coroutine-local context store, exactly the
        // production shape: two tenants drive the singleton concurrently, each
        // yielding mid-flight to force interleaving. Neither may see the other.
        $ctx = new CoroutineLocalTenantContextStore();
        $store = $this->freshStore($ctx);

        $seen = [];
        Coroutine\run(static function () use ($store, $ctx, &$seen): void {
            $barrier = new \Swoole\Coroutine\WaitGroup();

            $barrier->add(1);
            Coroutine::create(static function () use ($store, $ctx, &$seen, $barrier): void {
                $ctx->switchTo('acme');
                $store->append(ConversationStore::ROLE_USER, 'acme-1');
                Coroutine::sleep(0.02); // yield while globex runs
                $store->append(ConversationStore::ROLE_USER, 'acme-2');
                $seen['acme'] = array_column($store->turns(), 'text');
                $barrier->done();
            });

            $barrier->add(1);
            Coroutine::create(static function () use ($store, $ctx, &$seen, $barrier): void {
                $ctx->switchTo('globex');
                $store->append(ConversationStore::ROLE_USER, 'globex-1');
                Coroutine::sleep(0.02);
                $store->append(ConversationStore::ROLE_USER, 'globex-2');
                $seen['globex'] = array_column($store->turns(), 'text');
                $barrier->done();
            });

            $barrier->wait();
        });

        self::assertSame(['acme-1', 'acme-2'], $seen['acme'], 'Acme coroutine saw only Acme turns.');
        self::assertSame(['globex-1', 'globex-2'], $seen['globex'], 'Globex coroutine saw only Globex turns.');
    }
}

/** A tenant context store whose tenant is settable (sequential tests). */
final class MutableTenantContextStore implements TenantContextStoreInterface
{
    private ?TenantContextInterface $context = null;

    public function switchTo(string $tenantId): void
    {
        $this->context = new FixedTenantContext($tenantId);
    }

    public function get(): TenantContextInterface
    {
        return $this->context ?? new FixedTenantContext('default');
    }

    public function tryGet(): ?TenantContextInterface
    {
        return $this->context;
    }

    public function set(TenantContextInterface $context): void
    {
        $this->context = $context;
    }

    public function clear(): void
    {
        $this->context = null;
    }
}

/** Coroutine-local variant — the production store's exact isolation model. */
final class CoroutineLocalTenantContextStore implements TenantContextStoreInterface
{
    private const KEY = 'test.tenant.context';

    public function switchTo(string $tenantId): void
    {
        \Semitexa\Core\Support\CoroutineLocal::set(self::KEY, new FixedTenantContext($tenantId));
    }

    public function get(): TenantContextInterface
    {
        return $this->tryGet() ?? new FixedTenantContext('default');
    }

    public function tryGet(): ?TenantContextInterface
    {
        return \Semitexa\Core\Support\CoroutineLocal::get(self::KEY);
    }

    public function set(TenantContextInterface $context): void
    {
        \Semitexa\Core\Support\CoroutineLocal::set(self::KEY, $context);
    }

    public function clear(): void
    {
        \Semitexa\Core\Support\CoroutineLocal::remove(self::KEY);
    }
}

final class FixedTenantContext implements TenantContextInterface
{
    public function __construct(private readonly string $id) {}

    public function getTenantId(): string
    {
        return $this->id;
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function getLayer(TenantLayerInterface $layer): ?TenantLayerValueInterface
    {
        return null;
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function hasLayer(TenantLayerInterface $layer): bool
    {
        return false;
    }
}
