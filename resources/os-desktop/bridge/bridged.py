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
  GET /windows                         -> lists native app windows (best-effort).

Bound to localhost only. The launcher is served same-origin, so no CORS needed.
"""
import http.server, socketserver, urllib.parse, subprocess, os, json, shutil

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
    """Open a real folder/file in the right navigator. Returns (ok, opened_with)."""
    path = os.path.abspath(os.path.expanduser(path))
    if not os.path.exists(path):
        return (False, "not-found")
    mode = prefer if prefer in ("code", "files") else ("code" if looks_like_code(path) else "files")
    if mode == "code":
        for ed in EDITORS:
            exe = shutil.which(ed)
            if exe:
                spawn([exe, path])
                return (True, ed)
        # No editor installed → fall through to a generic opener.
    for op in OPENERS:
        exe = shutil.which(op)
        if exe:
            spawn([exe, path])
            return (True, op)
    return (False, "no-opener")

HOST, PORT = "127.0.0.1", 8777
BASE = os.path.dirname(os.path.abspath(__file__))
UDD  = os.path.expanduser("~/.config/semitexa-web")
CHROME = "chromium"
CHROME_FLAGS = [
    "--no-sandbox", "--disable-gpu", "--disable-infobars", "--noerrdialogs",
    "--no-first-run", "--no-default-browser-check", "--test-type",
    "--disable-features=Translate,TranslateUI",
    f"--user-data-dir={UDD}",
]

def open_url(url, w=900, h=640):
    if not (url.startswith("http://") or url.startswith("https://")):
        return False
    args = [CHROME, f"--app={url}", f"--window-size={w},{h}"] + CHROME_FLAGS
    subprocess.Popen(args, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                     start_new_session=True)
    return True

def spawn(cmd):
    subprocess.Popen(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                     start_new_session=True)

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

    def do_GET(self):
        u = urllib.parse.urlparse(self.path)
        q = urllib.parse.parse_qs(u.query)
        if u.path == "/":
            self.path = "/launcher.html"
            return super().do_GET()
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
        return super().do_GET()

    def log_message(self, *a):
        pass

if __name__ == "__main__":
    socketserver.TCPServer.allow_reuse_address = True
    with socketserver.TCPServer((HOST, PORT), Handler) as s:
        print(f"semitexa-bridge on http://{HOST}:{PORT}")
        s.serve_forever()
