<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Tenant\TenantContextAccess;
use Semitexa\Core\Tenant\TenantContextStoreInterface;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Query\Direction;
use Semitexa\Orm\Repository\DomainRepository;
use Semitexa\Os\Application\Db\MySQL\Model\ProcessResource;
use Semitexa\Os\Domain\Enum\ProcessStatus;

/**
 * The OS process registry — the ONE place anything doing work reports to, and
 * the ONE place the Chill "Processes" panel reads from.
 *
 * Producer contract (id is producer-chosen + namespaced, e.g. "task:<uuid>"):
 *   begin()     start/upsert a process (re-begin of a live id just refreshes it)
 *   progress()  set 0–100 (or null = indeterminate) + optional detail; bumps heartbeat
 *   heartbeat() "still alive" without new numbers
 *   complete()  terminal success   |   fail() terminal failure
 *
 * Honesty rules built in, not left to producers:
 *  - progress is clamped 0–99 while running — 100 is complete()'s to claim;
 *  - a running row whose heartbeat is older than STALL_TTL_SECONDS is demoted
 *    to 'stalled' lazily on read (no extra timer), so a dead producer's bar
 *    stops pretending within one poll.
 *
 * Persistence mirrors TaskStore (UUID-free manual ids, OrmManager, tenant-
 * scoped repository, readonly resource rebuilt via copy()).
 */
#[AsService]
final class ProcessRegistry
{
    /** Heartbeat silence after which a running process is demoted to stalled. */
    public const STALL_TTL_SECONDS = 30;

    /** Bounded list reads — completed processes accumulate (see TaskStore::MAX_LISTED). */
    private const MAX_LISTED = 100;

    /** Terminal rows older than this are purged opportunistically on report. */
    private const RETENTION_SECONDS = 86400;

    #[InjectAsReadonly]
    protected OrmManager $orm;

    #[InjectAsReadonly]
    protected TenantContextStoreInterface $tenantContextStore;

    private ?DomainRepository $repository = null;

    /** Test seam — production path uses property injection. */
    public function withTenantContextStore(TenantContextStoreInterface $store): self
    {
        $this->tenantContextStore = $store;

        return $this;
    }

    public function begin(
        string $id,
        string $source,
        string $title,
        string $origin = 'internal',
        ?int $progress = null,
        ?string $detail = null,
    ): ProcessResource {
        $now = new \DateTimeImmutable();
        $existing = $this->find($id);
        if ($existing !== null) {
            // Re-begin: same producer restarting its unit of work — reset the
            // row instead of failing (idempotent upsert, no read-first needed
            // by callers).
            return $this->save($this->copy($existing, [
                'source' => $this->slug($source),
                'origin' => $this->origin($origin),
                'title' => $this->title($title),
                'status' => ProcessStatus::Running->value,
                'progress' => $this->clampRunning($progress),
                'detail' => $this->detail($detail),
                'started_at' => $now,
                'updated_at' => $now,
                'completed_at' => null,
            ]));
        }

        $process = new ProcessResource(
            id: $this->storageKey($id),
            tenant_id: $this->currentTenantId(),
            source: $this->slug($source),
            origin: $this->origin($origin),
            title: $this->title($title),
            status: ProcessStatus::Running->value,
            started_at: $now,
            updated_at: $now,
            progress: $this->clampRunning($progress),
            detail: $this->detail($detail),
        );
        $this->scoped()->insert($process);
        $this->purgeExpired();

        return $process;
    }

    /** Progress update; null keeps the process indeterminate. Bumps the heartbeat. */
    public function progress(string $id, ?int $progress, ?string $detail = null): ?ProcessResource
    {
        $p = $this->find($id);
        if ($p === null || ProcessStatus::tryFrom($p->status)?->isFinal() === true) {
            return $p;
        }

        $changes = [
            'status' => ProcessStatus::Running->value, // a stalled process that reports again is alive
            'progress' => $this->clampRunning($progress),
            'updated_at' => new \DateTimeImmutable(),
        ];
        if ($detail !== null) {
            $changes['detail'] = $this->detail($detail);
        }

        return $this->save($this->copy($p, $changes));
    }

    /** "Still alive" without new numbers — keeps the stall reaper away. */
    public function heartbeat(string $id): ?ProcessResource
    {
        $p = $this->find($id);
        if ($p === null || ProcessStatus::tryFrom($p->status)?->isFinal() === true) {
            return $p;
        }

        return $this->save($this->copy($p, [
            'status' => ProcessStatus::Running->value,
            'updated_at' => new \DateTimeImmutable(),
        ]));
    }

    public function complete(string $id, ?string $detail = null): ?ProcessResource
    {
        return $this->finish($id, ProcessStatus::Done, $detail, progress: 100);
    }

    public function fail(string $id, ?string $detail = null): ?ProcessResource
    {
        return $this->finish($id, ProcessStatus::Failed, $detail, progress: null);
    }

    public function find(string $id): ?ProcessResource
    {
        return $this->scoped()->findById($this->storageKey($id));
    }

    /**
     * The registry view for the Processes panel, newest first, bounded:
     * running (freshly stall-demoted where the heartbeat went silent) plus
     * recently finished rows. The lazy demotion writes the row so every
     * consumer — and the producer itself — sees the same verdict.
     *
     * @return list<ProcessResource>
     */
    public function all(): array
    {
        /** @var list<ProcessResource> $rows */
        $rows = $this->scoped()->query()
            ->orderBy(ProcessResource::column('updated_at'), Direction::Desc)
            ->limit(self::MAX_LISTED)
            ->fetchAllAs(ProcessResource::class, $this->orm()->getMapperRegistry());

        $deadline = (new \DateTimeImmutable())->getTimestamp() - self::STALL_TTL_SECONDS;
        foreach ($rows as $i => $p) {
            if ($p->status === ProcessStatus::Running->value && $p->updated_at->getTimestamp() < $deadline) {
                $rows[$i] = $this->save($this->copy($p, ['status' => ProcessStatus::Stalled->value]));
            }
        }

        return $rows;
    }

    /**
     * @return array{id:string,source:string,origin:string,title:string,status:string,status_label:string,progress:?int,detail:?string,started_at:string,updated_at:string,completed_at:?string}
     */
    public function toArray(ProcessResource $p): array
    {
        $status = ProcessStatus::tryFrom($p->status) ?? ProcessStatus::Running;

        return [
            'id' => $this->producerId($p->id),
            'source' => $p->source,
            'origin' => $p->origin,
            'title' => $p->title,
            'status' => $p->status,
            'status_label' => $status->label(),
            'progress' => $p->progress,
            'detail' => $p->detail,
            'started_at' => $p->started_at->format('c'),
            'updated_at' => $p->updated_at->format('c'),
            'completed_at' => $p->completed_at?->format('c'),
        ];
    }

    private function finish(string $id, ProcessStatus $status, ?string $detail, ?int $progress): ?ProcessResource
    {
        $p = $this->find($id);
        if ($p === null) {
            return null;
        }

        $changes = [
            'status' => $status->value,
            'progress' => $progress,
            'updated_at' => new \DateTimeImmutable(),
            'completed_at' => new \DateTimeImmutable(),
        ];
        if ($detail !== null) {
            $changes['detail'] = $this->detail($detail);
        }

        return $this->save($this->copy($p, $changes));
    }

    /**
     * Terminal rows past retention are deleted opportunistically when new work
     * begins — the registry is a live panel, not an audit log (traces are).
     * Best-effort: a failure here must never break a producer's report.
     */
    private function purgeExpired(): void
    {
        try {
            $cutoff = (new \DateTimeImmutable())
                ->modify('-' . self::RETENTION_SECONDS . ' seconds')
                ->format('Y-m-d H:i:s');
            $this->orm()->getAdapter()->execute(
                'DELETE FROM `os_process`
                  WHERE tenant_id = :tenant_id
                    AND status IN (:done, :failed)
                    AND updated_at < :cutoff',
                [
                    'tenant_id' => $this->currentTenantId(),
                    'done' => ProcessStatus::Done->value,
                    'failed' => ProcessStatus::Failed->value,
                    'cutoff' => $cutoff,
                ],
            );
        } catch (\Throwable) {
            // best-effort housekeeping
        }
    }

    /** Running progress is capped at 99 — 100 belongs to complete() alone. */
    private function clampRunning(?int $progress): ?int
    {
        return $progress === null ? null : max(0, min(99, $progress));
    }

    private function id(string $id): string
    {
        $id = trim($id);
        if ($id === '') {
            throw new \InvalidArgumentException('Process id must not be empty.');
        }

        return mb_substr($id, 0, 128);
    }

    /**
     * The tenant-prefixed PK actually stored. The bare PK is table-global while
     * every read is tenant-filtered, so two tenants reusing one deterministic
     * producer id ("tasks:today:<date>") would collide on insert AND be
     * invisible to each other's find() — prefixing keeps the producer contract
     * ("my id, my namespace") true per tenant. 64 (tenant) + 1 + 128 (id) fits
     * the 200-char column.
     */
    private function storageKey(string $id): string
    {
        return $this->currentTenantId() . '|' . $this->id($id);
    }

    /** The producer-facing id — the storage key with the tenant prefix stripped. */
    private function producerId(string $storageId): string
    {
        $pos = strpos($storageId, '|');

        return $pos === false ? $storageId : substr($storageId, $pos + 1);
    }

    private function slug(string $source): string
    {
        $source = trim($source);

        return mb_substr($source !== '' ? $source : 'unknown', 0, 64);
    }

    private function origin(string $origin): string
    {
        return in_array($origin, ['internal', 'external', 'native'], true) ? $origin : 'internal';
    }

    private function title(string $title): string
    {
        $title = trim($title);

        return mb_substr($title !== '' ? $title : 'Working…', 0, 255);
    }

    private function detail(?string $detail): ?string
    {
        if ($detail === null) {
            return null;
        }
        $detail = trim($detail);

        return $detail === '' ? null : mb_substr($detail, 0, 255);
    }

    private function save(ProcessResource $p): ProcessResource
    {
        $this->scoped()->update($p);

        return $p;
    }

    /** @param array<string, mixed> $ch */
    private function copy(ProcessResource $p, array $ch): ProcessResource
    {
        return new ProcessResource(
            id: $p->id,
            tenant_id: $p->tenant_id,
            source: $ch['source'] ?? $p->source,
            origin: $ch['origin'] ?? $p->origin,
            title: $ch['title'] ?? $p->title,
            status: $ch['status'] ?? $p->status,
            started_at: $ch['started_at'] ?? $p->started_at,
            updated_at: $ch['updated_at'] ?? $p->updated_at,
            progress: array_key_exists('progress', $ch) ? $ch['progress'] : $p->progress,
            detail: array_key_exists('detail', $ch) ? $ch['detail'] : $p->detail,
            completed_at: array_key_exists('completed_at', $ch) ? $ch['completed_at'] : $p->completed_at,
        );
    }

    /** Repository bound to the ambient tenant — the ORM gate filters every query. */
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
        return $this->repository ??= $this->orm()->repository(ProcessResource::class, ProcessResource::class);
    }

    private function orm(): OrmManager
    {
        // isset() guard (not ??=) so the registry also works when constructed
        // bare (`new ProcessRegistry()`) — invocable skills do that.
        if (!isset($this->orm)) {
            $this->orm = new OrmManager();
        }

        return $this->orm;
    }
}
