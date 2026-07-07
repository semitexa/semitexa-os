<?php

declare(strict_types=1);

namespace Semitexa\Os\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\Os\Application\Service\ConversationStore;

/**
 * The transcript is unbounded and grows forever, yet turns() serves a request
 * path inside a Swoole worker. A default (limit=0) request must NOT load every
 * turn ever — it must return a bounded recent window, or a long-lived dialog
 * OOMs the worker. clear() must be one bulk DELETE, not a full materialise +
 * a delete per row.
 */
final class ConversationStoreBoundedReadTest extends TestCase
{
    private ConversationStore $store;
    private DatabaseAdapterInterface $db;

    protected function setUp(): void
    {
        $orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        $this->db = $orm->getAdapter();
        $this->db->execute(
            'CREATE TABLE os_conversation_turn (
                id TEXT PRIMARY KEY,
                tenant_id TEXT,
                role TEXT NOT NULL,
                text TEXT NOT NULL,
                meta_json TEXT NOT NULL DEFAULT "[]",
                created_at TEXT NOT NULL
            )',
        );

        $this->store = new ConversationStore();
        (new \ReflectionProperty(ConversationStore::class, 'orm'))->setValue($this->store, $orm);
    }

    #[Test]
    public function a_default_request_is_capped_and_never_loads_the_whole_transcript(): void
    {
        $this->seedTurns(620);

        $turns = $this->store->turns(0); // the unspecified-request default

        self::assertCount(500, $turns, 'A default fetch must be bounded to the hard cap.');
        self::assertSame(620, $this->store->count(), 'count() still reports the true total.');
        // Chronological, and the window is the MOST RECENT 500 (turn #121..#620).
        self::assertSame('turn-121', $turns[0]['text']);
        self::assertSame('turn-620', $turns[array_key_last($turns)]['text']);
    }

    #[Test]
    public function an_explicit_limit_below_the_cap_is_honoured(): void
    {
        $this->seedTurns(30);

        $turns = $this->store->turns(10);

        self::assertCount(10, $turns);
        self::assertSame('turn-21', $turns[0]['text']);
        self::assertSame('turn-30', $turns[array_key_last($turns)]['text']);
    }

    #[Test]
    public function an_explicit_limit_above_the_cap_is_clamped(): void
    {
        $this->seedTurns(700);

        self::assertCount(500, $this->store->turns(100_000));
    }

    #[Test]
    public function clear_removes_every_turn_in_one_shot(): void
    {
        $this->seedTurns(40);
        self::assertSame(40, $this->store->count());

        $this->store->clear();

        self::assertSame(0, $this->store->count());
        self::assertSame([], $this->store->turns(0));
    }

    private function seedTurns(int $n): void
    {
        // Zero-padded ids so ORDER BY id (production uses UUIDv7's chronological
        // ordering) is deterministic in the test, independent of clock/monotonic
        // details: id order == insertion order == turn number.
        for ($i = 1; $i <= $n; $i++) {
            $this->db->execute(
                'INSERT INTO os_conversation_turn (id, tenant_id, role, text, meta_json, created_at)
                 VALUES (:id, :tenant, :role, :text, :meta, :created)',
                [
                    'id' => sprintf('turn-%08d', $i),
                    'tenant' => 'default',
                    'role' => ConversationStore::ROLE_USER,
                    'text' => 'turn-' . $i,
                    'meta' => '[]',
                    'created' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'),
                ],
            );
        }
    }
}
