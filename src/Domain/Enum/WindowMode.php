<?php

declare(strict_types=1);

namespace Semitexa\Os\Domain\Enum;

/**
 * How the OS shell hosts the windows it raises.
 *
 * A Semitexa install runs in one of two deployment contexts, declared at
 * install time via the SEMITEXA_WINDOW_MODE env flag:
 *
 *  - {@see self::Web} — Semitexa runs on a shared / publicly reachable server.
 *    It owns no machine, so a {@see \Semitexa\Os\Domain\Enum\Surface::Window}
 *    dialog is rendered as an in-page CSS window hosting an `<iframe>`. This is
 *    the safe default (`.env.default`): it needs no local bridge and works for
 *    any browser client.
 *
 *  - {@see self::Os} — Semitexa has a personal machine at its disposal (the
 *    Semitexa OS Alpine box: its own window manager + a local bridge). Here a
 *    Window-kind dialog is *promoted* to a real, top-level native window via the
 *    bridge (which can escape iframe-embedding restrictions and spawn real OS
 *    programs such as a terminal). A whole tier of capability the web mode
 *    cannot offer.
 *
 * The env flag only declares that a mode is *permitted*. The shell still probes
 * at runtime (is this the desktop client, is the bridge reachable?) before it
 * promotes anything — so an `os` install always degrades gracefully to the web
 * (iframe) path for a plain browser client.
 */
enum WindowMode: string
{
    /** In-page iframe windows. Default; public-server-safe; no bridge needed. */
    case Web = 'web';

    /** Real native windows via the local bridge. Requires a personal machine. */
    case Os = 'os';
}
