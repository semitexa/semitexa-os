<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Session\SessionInterface;
use Semitexa\Os\Application\Payload\Session\OsAdminSessionSegment;
use Semitexa\Platform\User\Auth\UserPrincipal;
use Semitexa\Platform\User\Domain\Contract\PlatformUserRepositoryInterface;
use Semitexa\Platform\User\Domain\Model\PlatformUser;

/**
 * The console's own session: who is signed in, and the two acts that change it.
 *
 * Stateless by construction — the caller hands in the session it already holds.
 * A service that cached the resolved admin would have to be execution-scoped,
 * and an execution-scoped service cannot be injected into a handler at all (the
 * container refuses, to stop one frozen instance leaking one request's identity
 * into the next). Reading the row on every call also means a disabled account
 * loses access on its next request rather than at its next sign-in.
 */
#[AsService]
final class OsAdminSession
{
    #[InjectAsReadonly]
    protected PlatformUserRepositoryInterface $users;

    /** The signed-in admin, or null when nobody is. */
    public function current(?SessionInterface $session): ?PlatformUser
    {
        if ($session === null || !isset($this->users)) {
            return null;
        }

        $segment = $session->getPayload(OsAdminSessionSegment::class);
        if (!$segment->isSignedIn()) {
            return null;
        }

        $user = $this->users->findById((string) $segment->getUserId());

        if ($user === null || !$user->canAuthenticate()) {
            // Revoked, deleted or locked since they signed in: drop the session
            // rather than carry an identity the store no longer stands behind.
            $segment->signOut();
            $session->setPayload($segment);

            return null;
        }

        return $user;
    }

    public function currentPrincipal(?SessionInterface $session): ?UserPrincipal
    {
        $user = $this->current($session);

        return $user === null ? null : new UserPrincipal($user);
    }

    public function isSignedIn(?SessionInterface $session): bool
    {
        return $this->current($session) !== null;
    }

    /**
     * Bind this session to an admin.
     *
     * The session id is rotated first: the visitor arrived with an id anyone
     * could have handed them, and keeping it would let whoever planted it ride
     * the privileges they just gained.
     */
    public function signIn(SessionInterface $session, PlatformUser $user): void
    {
        $session->regenerate();

        $segment = $session->getPayload(OsAdminSessionSegment::class);
        $segment->signIn($user->getId());
        $session->setPayload($segment);
    }

    public function signOut(SessionInterface $session): void
    {
        $segment = $session->getPayload(OsAdminSessionSegment::class);
        $segment->signOut();
        $session->setPayload($segment);
        $session->regenerate();
    }

    /** Remember where they were going, so the sign-in can put them back. */
    public function rememberIntendedPath(SessionInterface $session, ?string $path): void
    {
        $segment = $session->getPayload(OsAdminSessionSegment::class);
        $segment->setIntendedPath($path);
        $session->setPayload($segment);
    }

    public function takeIntendedPath(?SessionInterface $session): ?string
    {
        if ($session === null) {
            return null;
        }

        $segment = $session->getPayload(OsAdminSessionSegment::class);
        $path = $segment->getIntendedPath();

        if ($path !== null) {
            $segment->setIntendedPath(null);
            $session->setPayload($segment);
        }

        return $path;
    }
}
