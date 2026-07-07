<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Update;

use Semitexa\Update\Attribute\AsDataPatch;
use Semitexa\Update\Context\DataPatchContext;
use Semitexa\Update\Domain\Contract\DataPatchInterface;
use Semitexa\Update\Domain\Enum\UpdatePhase;

/**
 * Backfill the tenant_id added when os_conversation_turn became #[TenantScoped].
 *
 * Pre-tenancy rows have tenant_id = NULL. The scoped store reads under
 * forTenant('default') (WHERE tenant_id = 'default'), so without this patch
 * every existing transcript turn becomes invisible AND unclearable after the
 * schema sync. 'default' is the sentinel TenantContextAccess::tenantIdOrDefault
 * returns for the no-context (single-tenant) case, so the existing transcript
 * keeps belonging to the default tenant. Idempotent: only touches NULL rows.
 */
#[AsDataPatch(
    id: 'backfill-conversation-tenant-id',
    module: 'semitexa/os',
    phase: UpdatePhase::Post,
    requiresColumns: ['os_conversation_turn' => ['tenant_id']],
    description: 'Assign existing OS conversation turns to the default tenant.',
)]
final class BackfillConversationTenantId implements DataPatchInterface
{
    public function apply(DataPatchContext $ctx): void
    {
        $ctx->execute("UPDATE `os_conversation_turn` SET `tenant_id` = 'default' WHERE `tenant_id` IS NULL");
    }
}
