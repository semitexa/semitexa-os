<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service;

use Semitexa\Auth\Context\AuthContextStore;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Os\Pipeline\OsAdminGate;
use Semitexa\Platform\User\Auth\UserPrincipal;

/**
 * Whether the person of the running request opens a console surface, asked
 * from code that is about to send them to one: a skill that opens a window.
 * The open-web-app skill added an editor's site to the site's list and opened
 * a window the gate then refused.
 *
 * The role rule is the gate's ({@see OsAdminGate::roleOpens()}). Sign-in off
 * opens everything, as the gate does; a request with no console user opens
 * nothing.
 */
#[AsService]
final class OsConsoleAccess
{
    #[InjectAsReadonly]
    protected OsAuthPolicy $policy;

    /** @param class-string $surface */
    public function opens(string $surface): bool
    {
        if (!isset($this->policy) || !$this->policy->isRequired()) {
            return true;
        }

        $principal = AuthContextStore::getUser();

        return $principal instanceof UserPrincipal && OsAdminGate::roleOpens($principal->user->getRole(), $surface);
    }
}
