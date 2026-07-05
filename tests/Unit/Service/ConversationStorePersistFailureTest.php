<?php

declare(strict_types=1);

namespace Semitexa\Os\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\Os\Application\Service\ConversationStore;

/**
 * Transcript persistence is best-effort — a DB hiccup must never break the
 * conversation loop — but a dropped turn is a silent GAP in the record (a
 * user intent or assistant reply that never shows). append() must swallow the
 * failure AND log it, so the gap is detectable.
 *
 * Forced failure: a real sqlite OrmManager whose `os_conversation_turn` table
 * was never created, so insert() throws a genuine "no such table".
 */
final class ConversationStorePersistFailureTest extends TestCase
{
    private string $errorLogBefore = '';
    private string $logFile = '';

    protected function setUp(): void
    {
        $this->logFile = sys_get_temp_dir() . '/os-convo-log-' . bin2hex(random_bytes(6)) . '.log';
        $this->errorLogBefore = (string) ini_get('error_log');
        ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->errorLogBefore);
        if (is_file($this->logFile)) {
            @unlink($this->logFile);
        }
    }

    #[Test]
    public function a_failed_persist_is_swallowed_and_logged(): void
    {
        $store = new ConversationStore();
        // A real adapter with no schema: insert() hits "no such table".
        $orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        (new \ReflectionProperty(ConversationStore::class, 'orm'))->setValue($store, $orm);

        // Must NOT throw — the conversation loop keeps running.
        $store->append(ConversationStore::ROLE_USER, 'hello there');

        self::assertTrue(true, 'append() swallowed the persist failure without propagating.');

        $log = is_file($this->logFile) ? (string) file_get_contents($this->logFile) : '';
        self::assertStringContainsString('Conversation transcript turn dropped', $log);
        self::assertStringContainsString('role=user', $log);
    }

    #[Test]
    public function empty_text_is_ignored_without_touching_persistence(): void
    {
        $store = new ConversationStore();
        // No orm planted: if empty text were persisted, orm() would build a
        // real OrmManager and the call would do real work. It must short-circuit.
        $store->append(ConversationStore::ROLE_ASSISTANT, '   ');

        $log = is_file($this->logFile) ? (string) file_get_contents($this->logFile) : '';
        self::assertStringNotContainsString('Conversation transcript turn dropped', $log);
    }
}
