<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Db\MySQL\Model;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Attribute\TenantScoped;
use Semitexa\Orm\Metadata\HasColumnReferences;
use Semitexa\Orm\Metadata\HasRelationReferences;

/**
 * ORM resource for the rolling summary of one tenant's OS conversation.
 *
 * Exactly one row per tenant (the tenant id IS the primary key): the summary is
 * a single compacted view of everything OLDER than the verbatim window, so it is
 * upserted in place rather than appended. Distinct from
 * {@see ConversationTurnResource}, which is the append-only verbatim transcript.
 *
 * `covered_through_id` is the id of the newest transcript turn already folded
 * into `summary_text`; turns after it are still replayed verbatim, so there is
 * never a gap between what the summary covers and what the window shows.
 *
 * Tenant-scoped (`same_storage`, mirroring {@see ConversationTurnResource}): the
 * tenant gate filters every read and stamps every write, so one tenant can never
 * read or overwrite another's summary. The literal `'default'` sentinel is used
 * for the default/single-tenant context.
 */
#[FromTable(name: 'os_conversation_summary')]
#[TenantScoped(strategy: 'same_storage', column: 'tenant_id')]
final readonly class ConversationSummaryResource
{
    use HasColumnReferences;
    use HasRelationReferences;

    public function __construct(
        /** Owning tenant; also the primary key (one summary per tenant). */
        #[PrimaryKey(strategy: 'manual')]
        #[Column(type: MySqlType::Varchar, length: 64)]
        public string $tenant_id,

        #[Column(type: MySqlType::LongText)]
        public string $summary_text,

        /** One sentence naming the user's current cross-turn focus, or ''. */
        #[Column(type: MySqlType::LongText)]
        public string $active_intent,

        /** Id of the newest transcript turn already folded into the summary. */
        #[Column(type: MySqlType::Varchar, length: 36)]
        public string $covered_through_id,

        #[Column(type: MySqlType::Datetime)]
        public \DateTimeImmutable $updated_at,
    ) {}
}
