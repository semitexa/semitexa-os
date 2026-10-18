<?php

declare(strict_types=1);

namespace Semitexa\Os\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Support\CoroutineLocal;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\Os\Application\Handler\PayloadHandler\PromptsSaveHandler;
use Semitexa\Os\Application\Payload\Request\PromptsSavePayload;
use Semitexa\Prompt\Application\Service\PromptOverrideStore;

/**
 * A restore answers with the override now in effect. The editor used to read
 * the newest history entry back instead — but the version log is best-effort,
 * so when it fails that entry is the previous save and the editor showed text
 * that is not the one prompts resolve to.
 */
final class PromptsSaveHandlerRestoreTest extends TestCase
{
    private OrmManager $orm;

    /** @var array<mixed> The CLI store as it was before the test. */
    private array $cliStoreSnapshot = [];

    protected function setUp(): void
    {
        $store = (new ReflectionProperty(CoroutineLocal::class, 'cliStore'))->getValue();
        $this->cliStoreSnapshot = is_array($store) ? $store : [];
        CoroutineLocal::resetCliStore();

        $this->orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        $this->orm->getAdapter()->execute(
            'CREATE TABLE prompt_override (
                id TEXT PRIMARY KEY,
                tenant_id TEXT,
                prompt_id TEXT NOT NULL,
                system TEXT NOT NULL,
                base_hash TEXT,
                updated_at TEXT NOT NULL
            )',
        );
        $this->orm->getAdapter()->execute(
            'CREATE TABLE prompt_override_history (
                id TEXT PRIMARY KEY,
                tenant_id TEXT,
                prompt_id TEXT NOT NULL,
                version INTEGER NOT NULL,
                system TEXT NOT NULL,
                author TEXT NOT NULL,
                reason TEXT NOT NULL,
                created_at TEXT NOT NULL
            )',
        );
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(CoroutineLocal::class, 'cliStore'))->setValue(null, $this->cliStoreSnapshot);
    }

    #[Test]
    public function a_restore_returns_the_effective_text_even_when_the_version_log_fails(): void
    {
        $store = new PromptOverrideStore()->withOrmManager($this->orm);
        $store->set('social.topic', 'First body.');
        $store->set('social.topic', 'Second body.');
        // From here on the version log refuses every write; the override row does not.
        $this->orm->getAdapter()->execute(
            "CREATE TRIGGER history_down BEFORE INSERT ON prompt_override_history BEGIN SELECT RAISE(ABORT, 'down'); END",
        );

        $body = $this->restore($store, 'social.topic', '1');

        self::assertTrue($body['ok']);
        self::assertSame(1, $body['restored']);
        self::assertSame('First body.', $body['system']);
        // The newest history entry still carries the text the restore replaced.
        self::assertSame('Second body.', $store->history('social.topic')[0]['system']);
    }

    #[Test]
    public function an_unknown_version_is_refused(): void
    {
        $store = new PromptOverrideStore()->withOrmManager($this->orm);
        $store->set('social.topic', 'First body.');

        $body = $this->restore($store, 'social.topic', '9');

        self::assertFalse($body['ok']);
        self::assertArrayNotHasKey('system', $body);
    }

    /**
     * @return array<string, mixed>
     */
    private function restore(PromptOverrideStore $store, string $id, string $version): array
    {
        $handler = new PromptsSaveHandler();
        (new \ReflectionProperty(PromptsSaveHandler::class, 'overrides'))->setValue($handler, $store);

        $payload = new PromptsSavePayload();
        $payload->setId($id);
        $payload->setAction('restore');
        $payload->setVersion($version);

        $resource = (new \ReflectionClass(ResourceResponse::class))->newInstanceWithoutConstructor();
        $body = json_decode((string) $handler->handle($payload, $resource)->getContent(), true);
        self::assertIsArray($body);

        /** @var array<string, mixed> $body */
        return $body;
    }
}
