# Semitexa OS desktop (bridge + window manager)

The native pieces that turn a personal Linux machine into a Semitexa OS desktop,
used only when the install runs in **OS mode** (`SEMITEXA_WINDOW_MODE=os`). In
web mode none of this is touched — dialogs are in-page iframes.

| File | Role |
|---|---|
| `wm/semitexa-wm.c` | The reparenting window manager (C + Xlib/Xft). Draws the Semitexa dialog frame (navy titlebar, cyan underline, coral close, XShape rounded corners) and manages move/resize/min/max/close + the frameless `SemitexaDesktop` shell surface. Kept visually identical to the web `.os-win` window. |
| `bridge/bridged.py` | Tiny local HTTP daemon (`127.0.0.1:8777`). `GET /open?url=<http…>` opens a real top-level `chromium --app` window (escapes iframe X-Frame-Options); `GET /open?app=terminal` spawns a real xterm. The shell calls it to promote a `Surface::Window` dialog to a native window. It also serves the file listing used by `semitexa/files`. |
| `bridge/launcher.html` | Fallback desktop served at the bridge root, if the app isn't up. |
| `xinitrc` | The X session: cursor + wallpaper, launch the bridge, wait for the app, open the shell as a fullscreen `chromium --app=…/os?desktop=1`, then run `semitexa-wm`. |
| `install.sh` | Lays these down (default `~/semitexa-os`), compiles the WM, installs `~/.xinitrc`. Idempotent, backs up overwrites. |

## Addresses and ports

The defaults match the framework's development stack (app on port `9507`), not
a project made by the installer (default port `9502`, see `SWOOLE_PORT` in the
project's `.env`). Adjust them to your app:

- `xinitrc` waits for the app on `127.0.0.1:9507` and opens
  `http://127.0.0.1:9507/os?desktop=1`. These are written into the file, not
  read from the environment: edit both the port check and the URL (and `/os`, if
  you changed `SEMITEXA_OS_SHELL_PATH`). It also expects the files under
  `~/semitexa-os`.
- `bridge/bridged.py` reads `SEMITEXA_OS_APP_URL` (default
  `http://127.0.0.1:9507`, used to report native windows to the OS),
  `SEMITEXA_BRIDGE_PORT` (default `8777`), `SEMITEXA_BRIDGE_TOKEN` and
  `SEMITEXA_FILES_ROOT` (default `~`). The shell and `xinitrc` expect the bridge
  on port `8777`.
- `install.sh` installs into `SEMITEXA_OS_HOME` (default `~/semitexa-os`).

## Install on a target machine

```sh
sh install.sh          # copies + compiles; then:
pkill -x xinit         # (re)start the X session to apply
```

Alpine prereqs: `apk add build-base pkgconf libx11-dev libxft-dev libxext-dev python3 chromium xterm font-opensans`.

## Development

This directory is the **canonical source**; edit here and re-run `install.sh`
on the target machine. The WM change only takes effect after the X session
restarts.
