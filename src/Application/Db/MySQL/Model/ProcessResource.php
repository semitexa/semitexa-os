<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Db\MySQL\Model;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\Index;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Attribute\TenantScoped;
use Semitexa\Orm\Metadata\HasColumnReferences;
use Semitexa\Orm\Metadata\HasRelationReferences;

/**
 * ORM resource for one reported process in the OS process registry
 * (`os_process`) — the global "something is working on something" signal.
 *
 * Anything doing work registers here and keeps the row alive: backend
 * packages via {@see \Semitexa\Os\Application\Service\ProcessRegistry},
 * iframe UI-skills and the native VM bridge via POST /os/process/report.
 * The Chill "Processes" panel renders this table; it knows nothing about
 * the producers.
 *
 * The id is producer-chosen and namespaced ("task:<uuid>", "bridge:<n>") so
 * a producer can idempotently upsert its own process without a read-first.
 *
 * `progress` is NULL for indeterminate work (spinner, not a bar) — a
 * producer that cannot measure honestly must not invent percentages; that
 * is exactly the theater this registry replaces.
 *
 * `final readonly` per the ORM contract: mutations rebuild the row.
 */
#[FromTable(name: 'os_process')]
#[Index(columns: ['tenant_id', 'status'], name: 'idx_os_process_status')]
#[Index(columns: ['tenant_id', 'updated_at'], name: 'idx_os_process_updated')]
#[TenantScoped(strategy: 'same_storage', column: 'tenant_id')]
final readonly class ProcessResource
{
    use HasColumnReferences;
    use HasRelationReferences;

    public function __construct(
        /**
         * Storage key: `<tenant_id>|<producer id>` — the registry prefixes the
         * producer-chosen id with the ambient tenant because this PK is table-
         * global while reads are tenant-filtered; two tenants using the same
         * deterministic id ("tasks:today:<date>") must not collide. Producers
         * and the wire only ever see the un-prefixed id (ProcessRegistry maps
         * both directions).
         */
        #[PrimaryKey(strategy: 'manual')]
        #[Column(type: MySqlType::Varchar, length: 200)]
        public string $id,

        /** Owning tenant; the ORM gate filters every read by this. */
        #[Column(type: MySqlType::Varchar, length: 64, nullable: true)]
        public ?string $tenant_id,

        /** Who reports: a package/skill/app slug ("tasks", "weaver", "files-app"). */
        #[Column(type: MySqlType::Varchar, length: 64)]
        public string $source,

        /** Where the producer lives: 'internal' (PHP), 'external' (iframe app), 'native' (VM bridge). */
        #[Column(type: MySqlType::Varchar, length: 16)]
        public string $origin,

        #[Column(type: MySqlType::Varchar, length: 255)]
        public string $title,

        /** One of {@see \Semitexa\Os\Domain\Enum\ProcessStatus}. */
        #[Column(type: MySqlType::Varchar, length: 16)]
        public string $status,

        #[Column(type: MySqlType::Datetime)]
        public \DateTimeImmutable $started_at,

        /** Heartbeat: every report bumps this; silence past the TTL ⇒ stalled. */
        #[Column(type: MySqlType::Datetime)]
        public \DateTimeImmutable $updated_at,

        /** 0–100, or NULL = indeterminate (render a spinner, never a fake bar). */
        #[Column(type: MySqlType::Int, nullable: true)]
        public ?int $progress = null,

        /** One-line current step ("copying 3/12", "waiting for model"). */
        #[Column(type: MySqlType::Varchar, length: 255, nullable: true)]
        public ?string $detail = null,

        #[Column(type: MySqlType::Datetime, nullable: true)]
        public ?\DateTimeImmutable $completed_at = null,
    ) {}
}
