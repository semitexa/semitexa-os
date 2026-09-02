<?php

declare(strict_types=1);

namespace Semitexa\Os\Domain\Contract;

/**
 * Marks a payload as part of the OS console surface.
 *
 * `#[AsProtectedPayload]` gets these routes as far as "somebody is signed in",
 * which is not the same claim as "an operator of this console is signed in": a
 * host site may run its own visitor login, and its customers would satisfy that
 * attribute exactly as well as an admin does. {@see \Semitexa\Os\Pipeline\OsAdminGate}
 * closes that gap, and this interface is how it knows which routes to close it
 * on — a namespace check would miss the sibling app packages (tasks, files,
 * music, tictactoe) that mount their own windows under /os/app.
 *
 * The sign-in routes and the shell page deliberately do NOT carry it: the first
 * two are the door itself, and the third answers an anonymous visitor with a
 * redirect to that door rather than a status code no browser renders.
 */
interface OsSurfacePayloadInterface
{
}
