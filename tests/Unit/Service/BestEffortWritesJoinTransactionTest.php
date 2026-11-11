<?php

declare(strict_types=1);

namespace Semitexa\Os\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Environment;
use Semitexa\Core\Support\CoroutineLocal;
use Semitexa\Core\Tenant\TenantContextInterface;
use Semitexa\Core\Tenant\TenantContextStoreInterface;
use Semitexa\Core\Tenant\Layer\TenantLayerInterface;
use Semitexa\Core\Tenant\Layer\TenantLayerValueInterface;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\Exception\ConnectionLostException;
use Semitexa\Orm\Exception\DeadlockException;
use Semitexa\Orm\Exception\LockWaitTimeoutException;
use Semitexa\Orm\OrmManager;
use Semitexa\Os\Application\Service\ConversationSummaryStore;
use Semitexa\Os\Application\Service\ProcessRegistry;

/**
 * The OS stores' best-effort writes join the caller's transaction
 * (tk-getadapter-writes-escape-transactions). Through getAdapter() they ran on
 * a pooled connection of their own: in the Explorer sandbox, which always
 * rolls back, the conversation summary was saved for good.
 *
 * Joined, a write that failed may have ended that transaction. A deadlock or
 * a lost connection is therefore rethrown while one is open, instead of being
 * logged and dropped.
 */
final class BestEffortWritesJoinTransactionTest extends TestCase
{
    /** @var array<string, mixed> the CLI store as the test found it, put back after (review) */
    private array $savedCliStore = [];

    protected function setUp(): void
    {
        $this->savedCliStore = self::cliStore()->getValue();
        CoroutineLocal::resetCliStore();
    }

    protected function tearDown(): void
    {
        self::cliStore()->setValue(null, $this->savedCliStore);
    }

    private static function cliStore(): \ReflectionProperty
    {
        return new \ReflectionProperty(CoroutineLocal::class, 'cliStore');
    }

    #[Test]
    public function only_an_error_that_ended_the_open_transaction_is_rethrown(): void
    {
        $orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        foreach ([ConversationSummaryStore::class, ProcessRegistry::class] as $class) {
            $store = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
            (new \ReflectionProperty($class, 'orm'))->setValue($store, $orm);
            $rethrow = (new \ReflectionMethod($class, 'rethrowIfTheTransactionIsGone'))->getClosure($store);

            $outside = $this->rethrown($rethrow, new DeadlockException('deadlock'));
            $inside = $orm->getTransactionManager()->run(fn (): array => [
                $this->rethrown($rethrow, new DeadlockException('deadlock')),
                $this->rethrown($rethrow, new ConnectionLostException('gone away')),
                $this->rethrown($rethrow, new LockWaitTimeoutException('lock wait')),
                $this->rethrown($rethrow, new \RuntimeException('anything else')),
            ]);

            self::assertFalse($outside, "{$class}: outside a transaction the write stays best-effort");
            self::assertSame([true, true, false, false], $inside, "{$class}: deadlock and lost connection only; a lock wait rolls back just its statement");
        }
    }

    #[Test]
    public function a_summary_saved_in_a_rolled_back_transaction_is_not_kept(): void
    {
        $this->requireMysql();
        $tenant = 'tx-probe-' . bin2hex(random_bytes(6));
        $rows = null;
        $inside = null;
        $failure = null;
        $hooks = \Swoole\Runtime::getHookFlags();
        \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
        try {
            \Swoole\Coroutine\run(function () use ($tenant, &$rows, &$inside, &$failure): void {
                $orm = new OrmManager();
                try {
                    $store = (new \ReflectionClass(ConversationSummaryStore::class))->newInstanceWithoutConstructor();
                    (new \ReflectionProperty(ConversationSummaryStore::class, 'orm'))->setValue($store, $orm);
                    $store->withTenantContextStore(self::storeFor($tenant));
                    $probe = new \RuntimeException('deliberate rollback');
                    try {
                        $orm->getTransactionManager()->run(static function () use ($orm, $store, $probe, $tenant, &$inside): void {
                            $store->save('summary', 'intent', 'turn-1');
                            // The write happened, in this transaction: a no-op save() would pass the rollback check alone (review).
                            $inside = $orm->getQueryAdapter()->execute(
                                'SELECT summary_text FROM `os_conversation_summary` WHERE tenant_id = :t',
                                ['t' => $tenant],
                            )->rows[0]['summary_text'] ?? null;
                            throw $probe;
                        });
                    } catch (\RuntimeException $e) {
                        if ($e !== $probe) {
                            throw $e;
                        }
                    }
                    $rows = count($orm->getQueryAdapter()->execute(
                        'SELECT tenant_id FROM `os_conversation_summary` WHERE tenant_id = :t',
                        ['t' => $tenant],
                    )->rows);
                } catch (\Throwable $e) {
                    $failure = $e;
                } finally {
                    try {
                        $orm->getQueryAdapter()->execute('DELETE FROM `os_conversation_summary` WHERE tenant_id = :t', ['t' => $tenant]);
                    } catch (\Throwable $e) {
                        $failure ??= $e;
                    } finally {
                        $orm->shutdown();
                    }
                }
            });
        } finally {
            // Hooks off outside a coroutine (on there, they broke PDO in later
            // tests); every later Co\run() hooking as it did before.
            \Swoole\Runtime::enableCoroutine(0);
            \Swoole\Coroutine::set(['hook_flags' => $hooks ?: SWOOLE_HOOK_ALL]);
        }

        if ($failure !== null) {
            throw $failure;
        }
        self::assertSame('summary', $inside, 'the summary was written inside the transaction');
        self::assertSame(0, $rows, 'the summary rolled back with the transaction');
    }

    private function rethrown(\Closure $rethrow, \Throwable $e): bool
    {
        try {
            $rethrow($e);
        } catch (\Throwable $thrown) {
            self::assertSame($e, $thrown);

            return true;
        }

        return false;
    }

    private static function storeFor(string $tenantId): TenantContextStoreInterface
    {
        $context = new class ($tenantId) implements TenantContextInterface {
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

        return new class ($context) implements TenantContextStoreInterface {
            public function __construct(private readonly TenantContextInterface $context)
            {
            }

            public function get(): TenantContextInterface
            {
                return $this->context;
            }

            public function tryGet(): ?TenantContextInterface
            {
                return $this->context;
            }

            public function set(TenantContextInterface $context): void
            {
            }

            public function clear(): void
            {
            }
        };
    }

    private function requireMysql(): void
    {
        $orm = new OrmManager();
        try {
            $cliHost = Environment::getEnvValue('DB_CLI_HOST');
            $host = !file_exists('/.dockerenv') && $cliHost !== null && $cliHost !== '' ? $cliHost : Environment::getEnvValue('DB_HOST');
            if ($orm->getDriver() !== 'mysql' || $host === null || $host === '') {
                self::markTestSkipped('needs the MySQL pool: the escape shows only on a second connection');
            }
            // Configured and not answering, or no table: a failure, not a skip.
            $orm->getAdapter()->execute('SELECT 1 FROM `os_conversation_summary` LIMIT 1');
        } finally {
            $orm->shutdown();
        }
    }
}
