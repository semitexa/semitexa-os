<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Session\SessionInterface;
use Semitexa\Llm\Domain\Model\SkillScope;

/**
 * Turns "who is signed in" into "which skills exist for them".
 *
 * The scope comes from the user row, not from the request: the host, the query
 * string and the session all describe where someone is, while the row is the
 * only place that records what they were granted.
 *
 * An install with the gate switched off (a personal desktop) has no user row to
 * read, and there the operator is the only person at the machine — so the scope
 * is unrestricted. The moment auth is required, an unsigned request gets no
 * scope at all rather than a generous one; callers must treat null as "run
 * nothing".
 */
#[AsService]
final class OsSkillScope
{
    #[InjectAsReadonly]
    protected OsAuthPolicy $policy;

    #[InjectAsReadonly]
    protected OsAdminSession $admins;

    public function forSession(?SessionInterface $session): ?SkillScope
    {
        if (!isset($this->policy) || !$this->policy->isRequired()) {
            return SkillScope::unrestricted();
        }

        $admin = isset($this->admins) ? $this->admins->current($session) : null;

        if ($admin === null) {
            return null;
        }

        if ($admin->isOwner()) {
            return SkillScope::unrestricted();
        }

        $tenantId = $admin->getTenantId();

        // An admin bound to no tenant is a misconfiguration, not an operator:
        // only the owner role crosses tenant boundaries, and reading this row
        // as unrestricted would promote a data-entry mistake into full access.
        return $tenantId === '' ? null : SkillScope::forTenant($tenantId);
    }
}
