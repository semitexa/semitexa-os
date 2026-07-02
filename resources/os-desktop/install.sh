#!/bin/sh
# ─────────────────────────────────────────────────────────────────────────────
# Semitexa OS desktop installer.
#
# Lays down the native window manager + bridge + X session script on a machine
# that runs Semitexa in OS mode (SEMITEXA_WINDOW_MODE=os), and compiles the WM.
# Idempotent; backs up anything it overwrites. Run it AS the desktop user.
#
#   sh install.sh
#
# Prereqs (Alpine): apk add build-base pkgconf libx11-dev libxft-dev libxext-dev \
#                           python3 chromium xterm font-opensans
# After it finishes, (re)start the X session — e.g.  pkill -x xinit
# ─────────────────────────────────────────────────────────────────────────────
set -eu

SRC="$(cd "$(dirname "$0")" && pwd)"
BASE="${SEMITEXA_OS_HOME:-$HOME/semitexa-os}"

echo "Semitexa OS desktop → $BASE"

# 1. tooling check ------------------------------------------------------------
missing=""
for bin in cc pkg-config python3; do command -v "$bin" >/dev/null 2>&1 || missing="$missing $bin"; done
if [ -n "$missing" ]; then
  echo "ERROR: missing tools:$missing" >&2
  echo "  Alpine: apk add build-base pkgconf libx11-dev libxft-dev libxext-dev python3" >&2
  exit 1
fi
if ! pkg-config --exists x11 xft xext; then
  echo "ERROR: missing X dev libs (need x11 xft xext)." >&2
  echo "  Alpine: apk add libx11-dev libxft-dev libxext-dev" >&2
  exit 1
fi

# 2. lay down source ----------------------------------------------------------
mkdir -p "$BASE/bridge" "$BASE/wm"
cp "$SRC/bridge/bridged.py"    "$BASE/bridge/bridged.py"
cp "$SRC/bridge/launcher.html" "$BASE/bridge/launcher.html"
cp "$SRC/wm/semitexa-wm.c"     "$BASE/wm/semitexa-wm.c"
echo "  copied bridge + wm source"

# 3. compile the window manager ----------------------------------------------
( cd "$BASE/wm" \
  && [ -f semitexa-wm ] && cp -f semitexa-wm semitexa-wm.bak 2>/dev/null || true
  cd "$BASE/wm" \
  && cc -O2 -o semitexa-wm semitexa-wm.c $(pkg-config --cflags --libs x11 xft xext) )
echo "  compiled semitexa-wm"

# 4. install the X session script (backup first) ------------------------------
if [ -f "$HOME/.xinitrc" ] && ! cmp -s "$SRC/xinitrc" "$HOME/.xinitrc"; then
  cp "$HOME/.xinitrc" "$HOME/.xinitrc.bak"
  echo "  backed up existing ~/.xinitrc → ~/.xinitrc.bak"
fi
cp "$SRC/xinitrc" "$HOME/.xinitrc"
chmod +x "$HOME/.xinitrc"
echo "  installed ~/.xinitrc"

echo
echo "Done. (Re)start the X session to apply — e.g.:  pkill -x xinit"
