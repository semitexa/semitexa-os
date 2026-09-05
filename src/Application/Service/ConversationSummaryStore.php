<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Log\FallbackErrorLogger;
use Semitexa\Core\Tenant\TenantContextAccess;
use Semitexa\Core\Tenant\TenantContextStoreInterface;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Query\Operator;
use Semitexa\Orm\Repository\DomainRepository;
use Semitexa\Os\Application\Db\MySQL\Model\ConversationSummaryResource;
use Semitexa\Os\Domain\Model\ConversationSummary;

/**
 * The rolling summary of a tenant's OS conversation — one compacted view of
 * everything older than the verbatim window, upserted in place.
 *
 * Sibling to {@see ConversationStore} (the append-only verbatim transcript): the
 * orchestrator keeps the last few turns verbatim and folds the rest into here, so
 * a long dialogue stays cheap to replay without losing its thread. See
 * `ep-llm-orchestrator-v1 / tk-llm-rolling-summary`.
 *
 * @phpstan-type Summary array{summary: string, active_intent: string, covered_through_id: string}
 */
#[AsService]
final class ConversationSummaryStore
{
    #[InjectAsReadonly]
    protected OrmManager $orm;

    /** Ambient-tenant seam (coroutine-local), resolved at call time. */
    #[InjectAsReadonly]
    protected TenantContextStoreInterface $tenantContextStore;

    private ?DomainRepository $repository = null;

    /** Test seam — production path uses property injection. */
    public function withTenantContextStore(TenantContextStoreInterface $store): self
    {
        $this->tenantContextStore = $store;

        return $this;
    }

    /** Test seam — production path uses property injection. */
    public function withOrmManager(OrmManager $orm): self
    {
        $this->orm = $orm;
        $this->repository = null;

        return $this;
    }

    /**
     * The current rolling summary for the ambient tenant. An absent row (no
     * summary yet) reads back as empty strings + an empty cursor, so the caller
     * treats "never summarized" and "summarized to nothing" identically.
     *
     * @return Summary
     */
    public function get(): array
    {
        $empty = ['summary' => '', 'active_intent' => '', 'covered_through_id' => ''];

        try {
            /** @var list<ConversationSummary> $rows */
            $rows = $this->scoped()->query()
                ->where(ConversationSummaryResource::column('tenant_id'), Operator::Equals, $this->currentTenantId())
                ->limit(1)
                ->fetchAllAs(ConversationSummary::class, $this->orm()->getMapperRegistry());
        } catch (\Throwable $e) {
            // A read failure must degrade to "no summary" (the verbatim window
            // still carries recent context), never break the conversation loop.
            FallbackErrorLogger::log('Conversation summary read failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return $empty;
        }

        $row = $rows[0] ?? null;
        if ($row === null) {
            return $empty;
        }

        return [
            'summary' => $row->getSummaryText(),
            'active_intent' => $row->getActiveIntent(),
            'covered_through_id' => $row->getCoveredThroughId(),
        ];
    }

    /**
     * Upsert the summary for the ambient tenant. One row per tenant (tenant id is
     * the PK), so this is INSERT-or-UPDATE in a single statement. Best-effort — a
     * write hiccup drops the fold (the batch stays in the verbatim window and is
     * retried next turn), it never breaks the loop.
     */
    public function save(string $summary, string $activeIntent, string $coveredThroughId): void
    {
        try {
            $this->orm()->getAdapter()->execute(
                'INSERT INTO `os_conversation_summary`'
                . ' (`tenant_id`, `summary_text`, `active_intent`, `covered_through_id`, `updated_at`)'
                . ' VALUES (:tenant_id, :summary_text, :active_intent, :covered_through_id, :updated_at)'
                . ' ON DUPLICATE KEY UPDATE'
                . ' `summary_text` = VALUES(`summary_text`),'
                . ' `active_intent` = VALUES(`active_intent`),'
                . ' `covered_through_id` = VALUES(`covered_through_id`),'
                . ' `updated_at` = VALUES(`updated_at`)',
                [
                    'tenant_id' => $this->currentTenantId(),
                    'summary_text' => $summary,
                    'active_intent' => $activeIntent,
                    'covered_through_id' => $coveredThroughId,
                    'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ],
            );
        } catch (\Throwable $e) {
            FallbackErrorLogger::log('Conversation summary write dropped', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Start a fresh conversation — remove this tenant's summary. Best-effort,
     * matching {@see get()}/{@see save()}: a DELETE failure here must not throw
     * past `ConversationClearHandler`, where the transcript's own clear() may
     * already have succeeded — an unguarded throw would leave the client with an
     * unhandled 500 for a "clear conversation" action and a dangling summary row
     * whose `covered_through_id` still points at turns the transcript just wiped.
     */
    public function clear(): void
    {
        try {
            $this->orm()->getAdapter()->execute(
                'DELETE FROM `os_conversation_summary` WHERE `tenant_id` = :tenant_id',
                ['tenant_id' => $this->currentTenantId()],
            );
        } catch (\Throwable $e) {
            FallbackErrorLogger::log('Conversation summary clear failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function scoped(): DomainRepository
    {
        return $this->repository()->forTenant($this->currentTenantId());
    }

    private function currentTenantId(): string
    {
        $context = isset($this->tenantContextStore) ? $this->tenantContextStore->tryGet() : null;

        return TenantContextAccess::tenantIdOrDefault($context);
    }

    private function repository(): DomainRepository
    {
        return $this->repository ??= $this->orm()->repository(
            ConversationSummaryResource::class,
            ConversationSummary::class,
        );
    }

    private function orm(): OrmManager
    {
        return $this->orm ??= new OrmManager();
    }
}
