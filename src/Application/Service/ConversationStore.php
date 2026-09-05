<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Log\FallbackErrorLogger;
use Semitexa\Core\Tenant\TenantContextAccess;
use Semitexa\Core\Tenant\TenantContextStoreInterface;
use Semitexa\Orm\Application\Service\Uuid7;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Query\Direction;
use Semitexa\Orm\Query\Operator;
use Semitexa\Orm\Repository\DomainRepository;
use Semitexa\Os\Application\Db\MySQL\Model\ConversationTurnResource;
use Semitexa\Os\Domain\Model\ConversationTurn;

/**
 * The full dialog transcript — every turn of the conversation between the user
 * and the OS, both sides, in order, persisted in the database
 * (`os_conversation_turn`).
 *
 * Distinct from {@see OsSessionStore}, which keeps a small ring buffer of
 * *meaningful outcomes* for the Awakening State Snapshot and the suggestion
 * engine. This store keeps the whole conversation — user intents AND assistant
 * replies, across every decision type (answer / ask / refuse / confirm /
 * executed / open-dialog / error) — so nothing said is lost.
 *
 * Turns are keyed by a UUIDv7 whose millisecond timestamp prefix makes id order
 * chronological, so the transcript reads back in sequence.
 *
 * @phpstan-type Turn array{at: string, role: string, text: string, meta: array<string, mixed>}
 */
#[AsService]
final class ConversationStore
{
    public const ROLE_USER = 'user';
    public const ROLE_ASSISTANT = 'assistant';

    /**
     * Hard cap on turns returned by a single {@see turns()} call — the
     * transcript is unbounded and this runs in a Swoole worker, so no request
     * may load the whole history into memory. Generous enough for any UI
     * transcript view; the true total is available via {@see count()}.
     */
    private const MAX_RETURNED = 500;

    #[InjectAsReadonly]
    protected OrmManager $orm;

    /**
     * Ambient-tenant seam (coroutine-local): resolved AT CALL TIME so this
     * worker-scoped singleton stays per-request-correct without becoming
     * execution-scoped. Mirrors CalendarEventDbRepository.
     */
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
     * Append one turn. Empty text is ignored. Best-effort — persistence must
     * never break the loop.
     *
     * @param array<string, mixed> $meta
     * @return string the persisted turn's id, or '' if the text was empty or the
     *         write failed — callers that need to recognise "this exact turn"
     *         later (e.g. {@see SkillLoopRunner}'s current-turn dedupe) should
     *         treat '' as "no id available" and fall back accordingly, not as an
     *         error to surface — persistence here is best-effort by design.
     */
    public function append(string $role, string $text, array $meta = []): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $id = Uuid7::generate();
        try {
            $this->scoped()->insert(new ConversationTurn(
                id: $id,
                tenantId: $this->currentTenantId(),
                role: $role === self::ROLE_USER ? self::ROLE_USER : self::ROLE_ASSISTANT,
                text: $text,
                meta: $meta,
                createdAt: new \DateTimeImmutable(),
            ));
        } catch (\Throwable $e) {
            // Persistence stays best-effort — a DB hiccup must never break the
            // conversation loop — but a dropped turn leaves a silent GAP in the
            // transcript (a user intent or an assistant reply the record never
            // shows). Log the drop so the gap is detectable; the loop proceeds.
            FallbackErrorLogger::log('Conversation transcript turn dropped', [
                'role' => $role,
                'chars' => strlen($text),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return '';
        }

        return $id;
    }

    /**
     * The most-recent turns, oldest → newest (chronological). The transcript
     * grows without bound and this serves a request path inside a Swoole
     * worker, so the fetch is ALWAYS bounded: `$limit` is clamped to
     * {@see MAX_RETURNED}, and `0` (an unspecified request) means "the recent
     * window", NOT "every turn ever" — an unbounded fetch would OOM the worker
     * as the dialog grows. {@see count()} still reports the true total, so a
     * caller can show "recent N of total".
     *
     * @return list<Turn>
     */
    public function turns(int $limit = 0): array
    {
        $effective = ($limit > 0 && $limit < self::MAX_RETURNED) ? $limit : self::MAX_RETURNED;

        // Most-recent N via id DESC + limit, then flip back to chronological.
        $rows = $this->scoped()->query()
            ->orderBy(ConversationTurnResource::column('id'), Direction::Desc)
            ->limit($effective)
            ->fetchAllAs(ConversationTurn::class, $this->orm()->getMapperRegistry());
        $rows = array_reverse($rows);

        return array_map(
            static fn (ConversationTurn $row): array => [
                'at' => ($row->getCreatedAt() ?? new \DateTimeImmutable())->format('c'),
                'role' => $row->getRole(),
                'text' => $row->getText(),
                'meta' => $row->getMeta(),
            ],
            $rows,
        );
    }

    public function count(): int
    {
        return $this->scoped()->query()->count();
    }

    /**
     * The id of the newest turn (UUIDv7, so lexicographically latest = newest),
     * or '' if there are none. Used to seed the proactive-poll cursor so old
     * messages are never replayed as toasts.
     */
    public function latestId(): string
    {
        /** @var list<ConversationTurn> $rows */
        $rows = $this->scoped()->query()
            ->orderBy(ConversationTurnResource::column('id'), Direction::Desc)
            ->limit(1)
            ->fetchAllAs(ConversationTurn::class, $this->orm()->getMapperRegistry());

        return isset($rows[0]) ? $rows[0]->getId() : '';
    }

    /**
     * Turns created AFTER $afterId (UUIDv7 cursor, '' = from the beginning),
     * oldest → newest, capped at $limit. This is the weaver's read: it keeps a
     * durable cursor and consumes the transcript in ordered batches.
     *
     * @return list<array{id: string, at: string, role: string, text: string, meta: array<string, mixed>}>
     */
    public function turnsAfter(string $afterId, int $limit = 20): array
    {
        $query = $this->scoped()->query();
        if ($afterId !== '') {
            $query = $query->where(ConversationTurnResource::column('id'), Operator::GreaterThan, $afterId);
        }

        /** @var list<ConversationTurn> $rows */
        $rows = $query
            ->orderBy(ConversationTurnResource::column('id'), Direction::Asc)
            ->limit(max(1, $limit))
            ->fetchAllAs(ConversationTurn::class, $this->orm()->getMapperRegistry());

        return array_map(
            static fn (ConversationTurn $row): array => [
                'id' => $row->getId(),
                'at' => ($row->getCreatedAt() ?? new \DateTimeImmutable())->format('c'),
                'role' => $row->getRole(),
                'text' => $row->getText(),
                'meta' => $row->getMeta(),
            ],
            $rows,
        );
    }

    /**
     * Proactive assistant turns (meta.proactive === true) created AFTER $afterId,
     * oldest → newest. This is how the assistant "speaks first": a background job
     * appends a proactive turn, the shell polls /os/proactive and surfaces it.
     *
     * @return list<array{id: string, text: string, meta: array<string, mixed>}>
     */
    public function proactiveAfter(string $afterId, int $scan = 40): array
    {
        /** @var list<ConversationTurn> $rows */
        $rows = $this->scoped()->query()
            ->orderBy(ConversationTurnResource::column('id'), Direction::Desc)
            ->limit($scan)
            ->fetchAllAs(ConversationTurn::class, $this->orm()->getMapperRegistry());

        $out = [];
        foreach (array_reverse($rows) as $row) {
            if ($afterId !== '' && strcmp($row->getId(), $afterId) <= 0) {
                continue;
            }
            $meta = $row->getMeta();
            if (($meta['proactive'] ?? false) !== true) {
                continue;
            }
            $out[] = ['id' => $row->getId(), 'text' => $row->getText(), 'meta' => $meta];
        }

        return $out;
    }

    /**
     * Start a fresh conversation — remove every stored turn. One bulk DELETE,
     * not a full fetch + a delete per row: the transcript is unbounded, so the
     * old row-by-row path materialised the entire history and issued N delete
     * queries. `os_conversation_turn` is not live-scope-bound (no
     * `#[WatchScopes]`), so no invalidation is skipped by going direct.
     */
    public function clear(): void
    {
        // Tenant-filtered bulk DELETE — a raw unscoped DELETE would wipe EVERY
        // tenant's transcript. `os_conversation_turn` is not live-scope-bound
        // (no #[WatchScopes]), so no invalidation is skipped by going direct.
        $this->orm()->getAdapter()->execute(
            'DELETE FROM `os_conversation_turn` WHERE `tenant_id` = :tenant_id',
            ['tenant_id' => $this->currentTenantId()],
        );
    }

    /** Repository bound to the ambient tenant — the ORM gate filters every query. */
    private function scoped(): DomainRepository
    {
        return $this->repository()->forTenant($this->currentTenantId());
    }

    /**
     * The current tenant id, or the 'default' sentinel for the
     * default/single-tenant context — never null, so the fail-closed tenant
     * filter always binds a concrete value (CalendarEventDbRepository convention).
     */
    private function currentTenantId(): string
    {
        $context = isset($this->tenantContextStore) ? $this->tenantContextStore->tryGet() : null;

        return TenantContextAccess::tenantIdOrDefault($context);
    }

    private function repository(): DomainRepository
    {
        return $this->repository ??= $this->orm()->repository(
            ConversationTurnResource::class,
            ConversationTurn::class,
        );
    }

    private function orm(): OrmManager
    {
        return $this->orm ??= new OrmManager();
    }
}
