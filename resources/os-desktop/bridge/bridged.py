#!/usr/bin/env python3
"""
semitexa-bridge — the native/web bridge for Semitexa OS.

Serves the desktop launcher at / and exposes a tiny local API:
  GET /open?url=<http(s)-url>[&w=&h=]  -> opens the URL as a NATIVE top-level
                                          chromium --app window (bypasses the
                                          X-Frame-Options / iframe embedding ban).
  GET /open?app=terminal               -> opens a native xterm.
  GET /open?path=<abs>[&with=code|files] -> opens a real folder/file: a code
                                          project (has .git/package.json/…) in a
                                          code editor, otherwise the file manager.
                                          `with` forces the choice.
  GET /list?path=<dir>                 -> JSON directory listing (for the in-OS
                                          Files app), confined to SEMITEXA_FILES_ROOT.
  GET /read?path=<file>                -> JSON text content of a file (capped).
  GET /windows                         -> lists native app windows (best-effort).

Write API (the Files app's context menu) — POST, JSON body, same confinement:
  POST /fs/mkdir   {path, name}        -> create a folder under path
  POST /fs/mkdirp  {path}              -> create a folder path recursively
                                          (provisioning a project's folder)
  POST /fs/newfile {path, name}        -> create an empty file under path
  POST /fs/rename  {path, name}        -> rename the entry at path (same dir)
  POST /fs/delete  {path}              -> SOFT delete: move into the trash dir
                                          (<root>/.semitexa/trash/<ts>-<name>)
  POST /fs/archive {path}              -> zip the file/folder to a sibling .zip
  POST /fs/extract {path}              -> unzip a .zip into a sibling folder

The Files endpoints are confined to SEMITEXA_FILES_ROOT (default: the home dir)
so the in-OS file manager can browse real projects without exposing the whole
disk. Write endpoints additionally require a LOOPBACK Origin (or none, for
local tools): a remote web page must not be able to drive file mutations by
POSTing at 127.0.0.1:8777.

Bound to localhost only. The launcher is served same-origin, so no CORS needed.

Every side-effecting or file-reading endpoint (/open, /list, /read, /fs/*)
additionally requires the shared bridge token (X-Bridge-Token header or
?token=). A remote page can still FIRE a no-cors GET at 127.0.0.1:8777 (the
browser sends it before any policy can read the answer), so Host/Origin checks
alone cannot stop a blind /open?app=terminal — the token can, because a remote
origin has no way to READ it: GET /token only answers loopback origins, and
cross-origin reads of it are denied by CORS.
"""
import http.server, socketserver, urllib.parse, urllib.request, subprocess, os, json, shutil, threading, time, zipfile, secrets, hmac

# A folder is treated as a code project (→ editor) if it holds one of these.
CODE_MARKERS = (".git", "package.json", "composer.json", "pyproject.toml",
                "Cargo.toml", "go.mod", "pom.xml", "build.gradle", "Makefile")
CODE_EXTS = ("php", "js", "ts", "tsx", "jsx", "py", "go", "rs", "java", "rb",
             "c", "cpp", "h", "css", "html", "vue", "json", "yaml", "yml", "md", "sh")
# Editors tried in order; first one installed wins.
EDITORS = ("code", "codium", "cursor", "zed", "subl", "phpstorm", "webstorm", "idea")
# File managers / generic openers, in order.
OPENERS = ("xdg-open", "nautilus", "dolphin", "thunar", "nemo", "pcmanfm", "open")

def looks_like_code(path):
    try:
        if os.path.isfile(path):
            return path.rsplit(".", 1)[-1].lower() in CODE_EXTS
        if os.path.isdir(path):
            entries = os.listdir(path)
            return any(m in entries for m in CODE_MARKERS) \
                or any(e.endswith(".code-workspace") for e in entries)
    except OSError:
        pass
    return False

def open_path(path, prefer=""):
    """Open a real folder/file in the right navigator, CONFINED to FILES_ROOT.

    Without this confinement any web page could drive GET /open?path=/etc/... (or
    a code project outside the sandbox, whose editor may auto-run workspace
    tasks) since the endpoint is reachable cross-origin. Restrict it to the same
    root the Files app browses. Returns (ok, opened_with)."""
    p = _confined(path)
    if not p:
        return (False, "denied")
    if not os.path.exists(p):
        return (False, "not-found")
    mode = prefer if prefer in ("code", "files") else ("code" if looks_like_code(p) else "files")
    if mode == "code":
        for ed in EDITORS:
            exe = shutil.which(ed)
            if exe:
                spawn([exe, p])
                return (True, ed)
        # No editor installed → fall through to a generic opener.
    for op in OPENERS:
        exe = shutil.which(op)
        if exe:
            spawn([exe, p])
            return (True, op)
    return (False, "no-opener")

HOST = "127.0.0.1"
PORT = int(os.environ.get("SEMITEXA_BRIDGE_PORT", "8777"))
BASE = os.path.dirname(os.path.abspath(__file__))

def _load_token():
    """Shared secret gating every side-effecting endpoint.

    Priority: SEMITEXA_BRIDGE_TOKEN env, else a persisted per-user token file
    (generated once, 0600) so the token survives bridge restarts and open
    shell pages keep working across them."""
    env = os.environ.get("SEMITEXA_BRIDGE_TOKEN", "").strip()
    if env:
        return env
    d = os.path.expanduser("~/.config/semitexa-bridge")
    f = os.path.join(d, "token")
    try:
        with open(f) as fh:
            tok = fh.read().strip()
        if tok:
            return tok
    except OSError:
        pass
    tok = secrets.token_hex(16)
    try:
        os.makedirs(d, mode=0o700, exist_ok=True)
        fd = os.open(f, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
        with os.fdopen(fd, "w") as fh:
            fh.write(tok)
    except OSError:
        pass  # unpersisted token still protects this run
    return tok

TOKEN = _load_token()
UDD  = os.path.expanduser("~/.config/semitexa-web")
CHROME = "chromium"
CHROME_FLAGS = [
    "--no-sandbox", "--disable-gpu", "--disable-infobars", "--noerrdialogs",
    "--no-first-run", "--no-default-browser-check", "--test-type",
    "--disable-features=Translate,TranslateUI",
    f"--user-data-dir={UDD}",
]

# --- OS process registry (native producer) ----------------------------------
# The bridge reports native window opens to the OS app so the Chill
# "Processes" panel sees NATIVE work too, not only in-app producers.
# Best-effort: the desktop must keep working when the app is down.
OS_APP = os.environ.get("SEMITEXA_OS_APP_URL", "http://127.0.0.1:9507")
_proc_seq = [0]

def report_process(action, pid, **fields):
    body = {"action": action, "id": pid, "source": "bridge", "origin": "native"}
    body.update(fields)
    try:
        req = urllib.request.Request(
            OS_APP + "/os/process/report",
            data=json.dumps(body).encode(),
            headers={"Content-Type": "application/json"},
            method="POST",
        )
        urllib.request.urlopen(req, timeout=2).read()
    except Exception:
        pass  # registry down ≠ bridge down

def _count_chromium_windows():
    # All windows of one chromium instance share WM_CLASS; a count delta is the
    # only reliable "my window appeared" signal (a second --app hands the window
    # to the existing master process, so there is no child pid to wait on).
    try:
        out = subprocess.run(["xdotool", "search", "--onlyvisible", "--class", "chromium"],
                             capture_output=True, timeout=5)
        return len(out.stdout.split())
    except Exception:
        return -1

def _watch_window_appears(pid, before, timeout=20):
    # Complete the process when a NEW chromium window shows up; fail on timeout.
    # With no xdotool (count -1) just complete after a grace beat — never leave
    # a bar running on a guess.
    if before < 0:
        time.sleep(2)
        report_process("complete", pid, detail="launched (unverified)")
        return
    deadline = time.time() + timeout
    while time.time() < deadline:
        n = _count_chromium_windows()
        if n > before:
            report_process("complete", pid, detail="window opened")
            return
        time.sleep(0.5)
    report_process("fail", pid, detail="window did not appear in %ds" % timeout)

def open_url(url, w=900, h=640):
    if not (url.startswith("http://") or url.startswith("https://")):
        return False
    _proc_seq[0] += 1
    pid = "bridge:open:%d:%d" % (os.getpid(), _proc_seq[0])
    host = urllib.parse.urlparse(url).netloc or url
    before = _count_chromium_windows()
    report_process("begin", pid, title="Opening %s" % host, detail="native window")
    args = [CHROME, f"--app={url}", f"--window-size={w},{h}"] + CHROME_FLAGS
    subprocess.Popen(args, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                     start_new_session=True)
    threading.Thread(target=_watch_window_appears, args=(pid, before), daemon=True).start()
    return True

def spawn(cmd):
    subprocess.Popen(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                     start_new_session=True)

# --- Files API (for the in-OS file manager), confined to a root -------------
# realpath (not abspath) so the root itself can be a symlink and, crucially, so
# a symlink *under* the root that points outside it is resolved and then
# rejected by the prefix check below (abspath only collapses `..` lexically and
# would let such a symlink escape).
FILES_ROOT = os.path.realpath(os.path.expanduser(os.environ.get("SEMITEXA_FILES_ROOT", "~")))

def _confined(path):
    """Resolve a path (following symlinks) and confine it to FILES_ROOT; None if it escapes."""
    p = os.path.realpath(os.path.expanduser(path or FILES_ROOT))
    if p == FILES_ROOT or p.startswith(FILES_ROOT + os.sep):
        return p
    return None

def list_dir(path):
    p = _confined(path)
    if not p or not os.path.isdir(p):
        return None
    entries = []
    try:
        names = os.listdir(p)
    except OSError:
        return None
    for name in names:
        fp = os.path.join(p, name)
        try:
            is_dir = os.path.isdir(fp)
            st = os.stat(fp)
            entries.append({"name": name, "type": "dir" if is_dir else "file",
                            "size": st.st_size, "mtime": int(st.st_mtime)})
        except OSError:
            continue
    entries.sort(key=lambda e: (e["type"] != "dir", e["name"].lower()))
    return {"path": p, "root": FILES_ROOT,
            "parent": (os.path.dirname(p) if p != FILES_ROOT else None),
            "entries": entries}

# --- Files write API (context-menu operations), same confinement ------------
TRASH_DIR = os.path.join(FILES_ROOT, ".semitexa", "trash")

def _valid_name(name):
    """A single path segment typed by the user — no separators, no traversal."""
    return bool(name) and name not in (".", "..") and "/" not in name \
        and "\\" not in name and "\x00" not in name and len(name) <= 255

def _unique(path):
    """path, or path-2 / path-3… if it already exists (never overwrite)."""
    if not os.path.exists(path):
        return path
    base, ext = os.path.splitext(path)
    for i in range(2, 1000):
        cand = "%s-%d%s" % (base, i, ext)
        if not os.path.exists(cand):
            return cand
    return None

def fs_mkdir(path, name):
    p = _confined(path)
    if not p or not os.path.isdir(p):
        return {"error": "denied"}
    if not _valid_name(name):
        return {"error": "bad-name"}
    target = os.path.join(p, name)
    if os.path.exists(target):
        return {"error": "exists"}
    try:
        os.mkdir(target)
        return {"ok": True, "path": target}
    except OSError as e:
        return {"error": str(e)}

def fs_mkdirp(path):
    """Recursive mkdir for provisioning a project's folder. No per-segment name
    validation needed: _confined realpath-normalises the WHOLE target (`..` and
    symlinks included) and rejects anything escaping FILES_ROOT."""
    p = _confined(path)
    if not p:
        return {"error": "denied"}
    if os.path.exists(p):
        return {"ok": True, "path": p, "existed": True} if os.path.isdir(p) else {"error": "exists"}
    try:
        os.makedirs(p)
        return {"ok": True, "path": p}
    except OSError as e:
        return {"error": str(e)}

def fs_newfile(path, name):
    p = _confined(path)
    if not p or not os.path.isdir(p):
        return {"error": "denied"}
    if not _valid_name(name):
        return {"error": "bad-name"}
    target = os.path.join(p, name)
    if os.path.exists(target):
        return {"error": "exists"}
    try:
        with open(target, "x"):
            pass
        return {"ok": True, "path": target}
    except OSError as e:
        return {"error": str(e)}

def fs_rename(path, name):
    p = _confined(path)
    if not p or not os.path.exists(p) or p == FILES_ROOT:
        return {"error": "denied"}
    if not _valid_name(name):
        return {"error": "bad-name"}
    target = os.path.join(os.path.dirname(p), name)
    if os.path.exists(target):
        return {"error": "exists"}
    try:
        os.rename(p, target)
        return {"ok": True, "path": target}
    except OSError as e:
        return {"error": str(e)}

def fs_delete(path):
    """Soft delete: move into the trash dir — the OS never hard-deletes."""
    p = _confined(path)
    if not p or not os.path.exists(p) or p == FILES_ROOT:
        return {"error": "denied"}
    if p == os.path.realpath(TRASH_DIR):
        return {"error": "denied"}
    try:
        os.makedirs(TRASH_DIR, exist_ok=True)
        target = _unique(os.path.join(TRASH_DIR, "%d-%s" % (int(time.time()), os.path.basename(p))))
        shutil.move(p, target)
        return {"ok": True, "trash": target}
    except OSError as e:
        return {"error": str(e)}

def fs_archive(path):
    p = _confined(path)
    if not p or not os.path.exists(p) or p == FILES_ROOT:
        return {"error": "denied"}
    out = _unique(p + ".zip" if not os.path.isdir(p) else os.path.join(os.path.dirname(p), os.path.basename(p) + ".zip"))
    if not out:
        return {"error": "exists"}
    try:
        with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
            if os.path.isdir(p):
                base = os.path.dirname(p)
                for root, dirs, files in os.walk(p):
                    for f in files:
                        fp = os.path.join(root, f)
                        z.write(fp, os.path.relpath(fp, base))
            else:
                z.write(p, os.path.basename(p))
        return {"ok": True, "path": out}
    except OSError as e:
        return {"error": str(e)}

def fs_extract(path):
    p = _confined(path)
    if not p or not os.path.isfile(p) or not zipfile.is_zipfile(p):
        return {"error": "not-a-zip"}
    dest = _unique(os.path.join(os.path.dirname(p), os.path.basename(p)[:-4] or "extracted"))
    if not dest:
        return {"error": "exists"}
    try:
        os.mkdir(dest)
        with zipfile.ZipFile(p) as z:
            # zipfile.extract sanitizes absolute paths and '..' segments, so
            # entries cannot escape dest (no zip-slip).
            z.extractall(dest)
        return {"ok": True, "path": dest}
    except (OSError, zipfile.BadZipFile) as e:
        return {"error": str(e)}

FS_OPS = {
    "/fs/mkdir":   lambda b: fs_mkdir(b.get("path", ""), b.get("name", "")),
    "/fs/mkdirp":  lambda b: fs_mkdirp(b.get("path", "")),
    "/fs/newfile": lambda b: fs_newfile(b.get("path", ""), b.get("name", "")),
    "/fs/rename":  lambda b: fs_rename(b.get("path", ""), b.get("name", "")),
    "/fs/delete":  lambda b: fs_delete(b.get("path", "")),
    "/fs/archive": lambda b: fs_archive(b.get("path", "")),
    "/fs/extract": lambda b: fs_extract(b.get("path", "")),
}

def read_file(path, cap=200000):
    p = _confined(path)
    if not p or not os.path.isfile(p):
        return None
    try:
        with open(p, "r", errors="replace") as f:
            data = f.read(cap + 1)
        truncated = len(data) > cap
        return {"path": p, "content": data[:cap], "truncated": truncated}
    except (OSError, ValueError):
        return {"path": p, "content": None, "binary": True}

class Handler(http.server.SimpleHTTPRequestHandler):
    def __init__(self, *a, **k):
        super().__init__(*a, directory=BASE, **k)

    def _json(self, obj, code=200):
        b = json.dumps(obj).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(b)))
        # The OS shell is served from another local port (the Swoole app);
        # it must be able to read /open results cross-origin.
        self.send_header("Access-Control-Allow-Origin", "*")
        self.end_headers()
        self.wfile.write(b)

    def _host_ok(self):
        # DNS-rebinding guard: only serve requests addressed to the loopback
        # bridge by its real host:port. A page that rebinds its own domain to
        # 127.0.0.1 still sends `Host: attacker.example`, which we reject —
        # closing the remote path to the file/app-launch endpoints below.
        return self.headers.get("Host", "") in (f"{HOST}:{PORT}", f"localhost:{PORT}")

    def _origin_ok(self):
        # Write ops mutate real files, so the caller must be a LOCAL page (the
        # OS shell / Files app on a loopback port) or a local tool (no Origin
        # header at all). Browsers always send Origin on cross-origin POSTs —
        # a remote web page can therefore never drive /fs/*.
        origin = self.headers.get("Origin", "")
        if origin == "":
            return True
        host = urllib.parse.urlparse(origin).hostname or ""
        return host in ("127.0.0.1", "localhost", "::1")

    def _token_ok(self, q):
        # The shared-token gate on side effects. Host/Origin checks cannot stop
        # a BLIND cross-origin GET (the browser fires it regardless of CORS);
        # only a value the remote page cannot read can. Constant-time compare.
        given = self.headers.get("X-Bridge-Token", "") or q.get("token", [""])[0]
        return bool(given) and hmac.compare_digest(given, TOKEN)

    def do_GET(self):
        if not self._host_ok():
            self.send_response(403)
            self.end_headers()
            return
        u = urllib.parse.urlparse(self.path)
        q = urllib.parse.parse_qs(u.query)
        if u.path == "/":
            self.path = "/launcher.html"
            return super().do_GET()
        if u.path == "/token":
            # Hands the token to LOCAL pages only (the OS shell on the app's
            # loopback port, the launcher same-origin, local tools with no
            # Origin). A remote origin is refused here and — belt and braces —
            # could not read the response anyway without a CORS grant.
            if not self._origin_ok():
                return self._json({"error": "denied"}, 403)
            return self._json({"token": TOKEN})
        if u.path in ("/open", "/list", "/read") and not self._token_ok(q):
            return self._json({"error": "token-required"}, 403)
        if u.path == "/open":
            url = q.get("url", [""])[0]
            app = q.get("app", [""])[0]
            path = q.get("path", [""])[0]
            w = int(q.get("w", ["900"])[0])
            h = int(q.get("h", ["640"])[0])
            if path:
                ok, how = open_path(path, q.get("with", [""])[0])
                return self._json({"ok": ok, "path": path, "opened_with": how}, 200 if ok else 400)
            if app == "terminal":
                spawn(["xterm", "-fn", "9x15", "-bg", "#0d1f33",
                       "-fg", "#cfe3ff", "-title", "Terminal"])
                return self._json({"ok": True, "app": "terminal"})
            ok = open_url(url, w, h) if url else False
            return self._json({"ok": ok, "url": url}, 200 if ok else 400)
        if u.path == "/list":
            r = list_dir(q.get("path", [""])[0])
            return self._json(r if r else {"error": "not-a-directory-or-denied"}, 200 if r else 400)
        if u.path == "/read":
            r = read_file(q.get("path", [""])[0])
            return self._json(r if r else {"error": "not-a-file-or-denied"}, 200 if r else 400)
        return super().do_GET()

    def do_OPTIONS(self):
        # CORS preflight for the JSON POSTs below (the Files app lives on the
        # app's port, so /fs/* calls are cross-origin). Only acknowledged for
        # loopback origins — anyone else gets no CORS grant at all.
        if not self._host_ok() or not self._origin_ok():
            self.send_response(403)
            self.end_headers()
            return
        self.send_response(204)
        self.send_header("Access-Control-Allow-Origin", self.headers.get("Origin", "*"))
        self.send_header("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
        self.send_header("Access-Control-Allow-Headers", "Content-Type, X-Bridge-Token")
        self.send_header("Access-Control-Max-Age", "600")
        self.end_headers()

    def do_POST(self):
        if not self._host_ok() or not self._origin_ok():
            self.send_response(403)
            self.end_headers()
            return
        u = urllib.parse.urlparse(self.path)
        if not self._token_ok(urllib.parse.parse_qs(u.query)):
            return self._json({"error": "token-required"}, 403)
        op = FS_OPS.get(u.path)
        if not op:
            return self._json({"error": "unknown-endpoint"}, 404)
        try:
            length = min(int(self.headers.get("Content-Length", "0")), 65536)
            body = json.loads(self.rfile.read(length) or b"{}")
            if not isinstance(body, dict):
                raise ValueError
        except (ValueError, json.JSONDecodeError):
            return self._json({"error": "bad-json"}, 400)
        r = op(body)
        return self._json(r, 200 if r.get("ok") else 400)

    def log_message(self, *a):
        pass

if __name__ == "__main__":
    socketserver.TCPServer.allow_reuse_address = True
    with socketserver.TCPServer((HOST, PORT), Handler) as s:
        print(f"semitexa-bridge on http://{HOST}:{PORT}")
        s.serve_forever()
