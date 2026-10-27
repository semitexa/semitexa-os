<?php

declare(strict_types=1);

namespace Semitexa\Os\Auth;

use Semitexa\Core\Auth\AuthenticatableInterface;

/**
 * The person at a machine whose console asks for no sign-in (an `os` install,
 * the default there): presented for the console's own routes only.
 *
 * Those routes are `#[AsProtectedPayload]`, and with nobody signed in every
 * one of them answered 401 — the shell opened and nothing behind it did. The
 * console's own gate already let this person through; the authorization
 * layer before it had nobody to let through.
 */
final readonly class OsDesktopPrincipal implements AuthenticatableInterface
{
    public function getId(): string
    {
        return 'os-desktop';
    }

    public function getAuthIdentifierName(): string
    {
        return 'os_desktop';
    }

    public function getAuthIdentifier(): string
    {
        return 'os-desktop';
    }
}
