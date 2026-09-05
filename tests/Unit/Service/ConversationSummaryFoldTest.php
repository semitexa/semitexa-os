<?php

declare(strict_types=1);

namespace Semitexa\Os\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Llm\Application\Service\LlmProviderResolver;
use Semitexa\Llm\Domain\Contract\LlmProviderInterface;
use Semitexa\Llm\Domain\Model\LlmRequest;
use Semitexa\Llm\Domain\Model\LlmResponse;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\Os\Application\Service\ConversationStore;
use Semitexa\Os\Application\Service\ConversationSummaryStore;
use Semitexa\Os\Application\Service\SkillLoopRunner;

/**
 * The rolling summary — the mechanism that keeps a long conversation cheap to
 * replay without the assistant forgetting how it started.
 *
 * It was audited as unproven rather than broken: os_conversation_summary had no
 * rows on any install we could look at, so nothing showed the fold had ever
 * executed. It has since been driven end to end against a real model, which
 * folded six turns into an accurate Ukrainian summary. This keeps that proven
 * without needing a model.
 */
final class ConversationSummaryFoldTest extends TestCase
{
    /** LIVE_WINDOW (8) + FOLD_BATCH (6): the tail must exceed both to fold once. */
    private const TURNS = 16;

    private ConversationStore $conversation;

    private ConversationSummaryStore $summaries;

    /**
     * Persistence is asserted through the history rather than the store: the
     * store's write is a MySQL `ON DUPLICATE KEY UPDATE`, which the in-memory
     * SQLite this suite runs on cannot execute. Saving was proven separately by
     * driving sixteen real turns through a live model against MySQL, which
     * folded six of them and wrote the row; what belongs here is the behaviour
     * that must not regress without one.
     */
    #[Test]
    public function a_conversation_past_the_window_folds_its_oldest_turns_into_a_summary(): void
    {
        $history = $this->plannerHistory($this->runner());

        self::assertLessThan(
            self::TURNS,
            count($history),
            'the fold never ran — every turn is still being replayed verbatim',
        );
        self::assertStringContainsString('folded summary', $history[0]['content']);
    }

    /**
     * The summary rides as a single leading context message rather than being
     * spliced into the system prompt, so the large static prefix stays
     * byte-identical and cacheable.
     */
    #[Test]
    public function the_summary_leads_the_history_as_one_context_message(): void
    {
        $history = $this->plannerHistory($this->runner());

        self::assertStringContainsString('folded summary', $history[0]['content']);
        self::assertGreaterThan(1, count($history), 'the verbatim window must still follow the summary');
        self::assertStringContainsString('turn number', $history[1]['content']);
    }

    /**
     * A provider outage must not silently drop turns: the cursor stays put so
     * the batch is retried rather than vanishing from the model's context.
     */
    #[Test]
    public function a_failed_summarization_leaves_the_turns_uncovered(): void
    {
        $history = $this->plannerHistory($this->runner(success: false));

        self::assertSame('', $this->summaries->get()['covered_through_id'], 'nothing may be recorded as covered');
        self::assertCount(self::TURNS, $history, 'every turn stays verbatim rather than being lost');
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    private function plannerHistory(SkillLoopRunner $runner): array
    {
        $method = new \ReflectionMethod(SkillLoopRunner::class, 'plannerHistory');

        /** @var list<array{role: string, content: string}> $history */
        $history = $method->invoke($runner, 'what were we saying?', '');

        return $history;
    }

    private function runner(bool $success = true): SkillLoopRunner
    {
        $orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        $db = $orm->getAdapter();
        $db->execute(
            'CREATE TABLE os_conversation_turn (
                id TEXT PRIMARY KEY,
                tenant_id TEXT,
                role TEXT NOT NULL,
                text TEXT NOT NULL,
                meta_json TEXT NOT NULL DEFAULT "[]",
                created_at TEXT NOT NULL
            )',
        );
        $db->execute(
            'CREATE TABLE os_conversation_summary (
                tenant_id TEXT PRIMARY KEY,
                summary_text TEXT NOT NULL,
                active_intent TEXT NOT NULL,
                covered_through_id TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )',
        );

        $this->conversation = new ConversationStore();
        (new \ReflectionProperty(ConversationStore::class, 'orm'))->setValue($this->conversation, $orm);

        $this->summaries = new ConversationSummaryStore();
        $this->summaries->withOrmManager($orm);

        for ($i = 1; $i <= self::TURNS; $i++) {
            $this->conversation->append(
                $i % 2 === 1 ? ConversationStore::ROLE_USER : ConversationStore::ROLE_ASSISTANT,
                'turn number ' . $i,
            );
        }

        $provider = new class($success) implements LlmProviderInterface {
            public function __construct(private bool $ok)
            {
            }

            public function name(): string
            {
                return 'fake';
            }

            public function baseUrl(): string
            {
                return '';
            }

            public function model(): string
            {
                return 'fake';
            }

            public function healthCheck(): bool
            {
                return true;
            }

            public function complete(LlmRequest $request): LlmResponse
            {
                return $this->ok
                    ? new LlmResponse('{"summary":"a folded summary of the earlier turns","active_intent":"catching up"}', true)
                    : new LlmResponse('', false, 'provider unreachable');
            }
        };

        $runner = new SkillLoopRunner();
        (new \ReflectionProperty(SkillLoopRunner::class, 'providers'))
            ->setValue($runner, (new LlmProviderResolver())->withProvider($provider));

        (new \ReflectionProperty(SkillLoopRunner::class, 'summaries'))->setValue($runner, $this->summaries);
        (new \ReflectionProperty(SkillLoopRunner::class, 'conversation'))->setValue($runner, $this->conversation);

        return $runner;
    }
}
