# Semitexa OS desktop (bridge + window manager)

The native pieces that turn a personal Linux machine into a Semitexa OS desktop,
used only when the install runs in **OS mode** (`SEMITEXA_WINDOW_MODE=os`). In
web mode none of this is touched — dialogs are in-page iframes.

| File | Role |
|---|---|
| `wm/semitexa-wm.c` | The reparenting window manager (C + Xlib/Xft). Draws the Semitexa dialog frame (navy titlebar, cyan underline, coral close, XShape rounded corners) and manages move/resize/min/max/close + the frameless `SemitexaDesktop` shell surface. Kept visually identical to the web `.os-win` — see `var/docs/os-window-spec.md`. |
| `bridge/bridged.py` | Tiny local HTTP daemon (`127.0.0.1:8777`). `GET /open?url=<http…>` opens a real top-level `chromium --app` window (escapes iframe X-Frame-Options); `GET /open?app=terminal` spawns a real xterm. The shell calls it to promote a `Surface::Window` dialog to a native window. |
| `bridge/launcher.html` | Fallback desktop served at the bridge root, if the app isn't up. |
| `xinitrc` | The X session: cursor + wallpaper, launch the bridge, wait for the app, open the shell as a fullscreen `chromium --app=…/os?desktop=1`, then run `semitexa-wm`. |
| `install.sh` | Lays these down (default `~/semitexa-os`), compiles the WM, installs `~/.xinitrc`. Idempotent, backs up overwrites. |

## Install on a target machine

```sh
sh install.sh          # copies + compiles; then:
pkill -x xinit         # (re)start the X session to apply
```

Alpine prereqs: `apk add build-base pkgconf libx11-dev libxft-dev libxext-dev python3 chromium xterm font-opensans`.

## Development

This directory is the **canonical source**. Edit here, then sync to the dev VM
with `scripts/os-dev.sh desktop-deploy` (ships the files + recompiles the WM);
`scripts/os-dev.sh desktop-diff` (and `doctor`) report any local↔VM drift. The
WM change only takes effect after `scripts/os-dev.sh restart-session`.
