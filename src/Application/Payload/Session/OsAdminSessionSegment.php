<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Payload\Session;

use Semitexa\Core\Session\Attribute\SessionSegment;

/**
 * Who is signed in to the OS console, kept apart from whatever else the host
 * site stores in the session.
 *
 * A public site may well have its own visitor login writing the shared `auth`
 * segment. Reusing it here would mean any signed-in customer satisfies the OS
 * console's gate, so the console keeps its own segment: an admin session is a
 * deliberate, separate act from being a visitor.
 */
/*
 * The plain setters below are not decoration: Session::getPayload() rehydrates
 * a segment through PayloadSerializer::hydrate(), which calls set{Key}() with
 * exactly one argument and skips anything else. A segment whose only mutator is
 * a multi-argument intent method (signIn) writes fine and reads back empty — it
 * looks like a session that silently forgets, and the reason is invisible at
 * every call site.
 */
#[SessionSegment('os_admin')]
final class OsAdminSessionSegment
{
    private ?string $userId = null;
    private ?int $signedInAt = null;
    /** Where the visitor was heading before the form interrupted them. */
    private ?string $intendedPath = null;

    public function getUserId(): ?string
    {
        return $this->userId;
    }

    public function getSignedInAt(): ?int
    {
        return $this->signedInAt;
    }

    public function isSignedIn(): bool
    {
        return $this->userId !== null && $this->userId !== '';
    }

    /** @see the class note — required for rehydration, not for callers. */
    public function setUserId(?string $userId): void
    {
        $userId = $userId === null ? null : trim($userId);
        $this->userId = $userId === '' ? null : $userId;
    }

    /** @see the class note — required for rehydration, not for callers. */
    public function setSignedInAt(?int $signedInAt): void
    {
        $this->signedInAt = $signedInAt;
    }

    public function signIn(string $userId, ?int $at = null): void
    {
        $userId = trim($userId);
        if ($userId === '') {
            throw new \InvalidArgumentException('OsAdminSessionSegment userId must be a non-empty string.');
        }

        $this->userId = $userId;
        $this->signedInAt = $at ?? time();
        $this->intendedPath = null;
    }

    public function signOut(): void
    {
        $this->userId = null;
        $this->signedInAt = null;
        $this->intendedPath = null;
    }

    public function getIntendedPath(): ?string
    {
        return $this->intendedPath;
    }

    public function setIntendedPath(?string $path): void
    {
        $this->intendedPath = $path;
    }
}
