#!/usr/bin/env python3
"""
Security regression test for the semitexa-bridge path confinement.

The bridge is HTTP-reachable from the browser, so /open, /list and /read must
never resolve a path outside SEMITEXA_FILES_ROOT — not via `..`, not via an
absolute path, and not via a symlink under the root that points outside it.
open_path() (which launches an editor / file manager) must additionally be
confined, or any web page could drive it to open arbitrary host files.

Also covers the shared-token gate over real HTTP: /open, /list, /read and
POST /fs/* must refuse without X-Bridge-Token (a remote page can fire blind
no-cors GETs that Host/Origin checks cannot stop), and GET /token must hand
the token only to requests a loopback page could make.

Run: python3 test_bridged_confinement.py
"""
import os
import json
import tempfile
import threading
import importlib.util
import socketserver
import urllib.request
import urllib.error


TEST_TOKEN = "test-bridge-token-1234"


def load_bridge(root):
    os.environ["SEMITEXA_FILES_ROOT"] = root
    os.environ["SEMITEXA_BRIDGE_TOKEN"] = TEST_TOKEN
    here = os.path.dirname(os.path.abspath(__file__))
    spec = importlib.util.spec_from_file_location("bridged", os.path.join(here, "bridged.py"))
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


def http(port, method, path, headers=None, body=None):
    """Raw request against the live bridge; returns (status, parsed-json|None)."""
    req = urllib.request.Request(
        f"http://127.0.0.1:{port}{path}",
        data=(json.dumps(body).encode() if body is not None else None),
        headers=headers or {},
        method=method,
    )
    try:
        with urllib.request.urlopen(req, timeout=5) as r:
            raw = r.read()
            return r.status, (json.loads(raw) if raw else None)
    except urllib.error.HTTPError as e:
        raw = e.read()
        try:
            return e.code, (json.loads(raw) if raw else None)
        except json.JSONDecodeError:
            return e.code, None


def run_http_token_tests(b, root):
    spawned = []
    b.spawn = lambda cmd: spawned.append(cmd)  # never launch real programs

    # Bind port 0 and TELL the module which port it got — binding a
    # pre-probed free port would race other processes for it. _host_ok
    # reads the module global per request, so patching it here is enough.
    srv = socketserver.TCPServer((b.HOST, 0), b.Handler)
    b.PORT = srv.server_address[1]
    threading.Thread(target=srv.serve_forever, daemon=True).start()
    port = b.PORT
    tok = {"X-Bridge-Token": TEST_TOKEN}
    try:
        # --- /token distribution: loopback-page requests only.
        code, d = http(port, "GET", "/token")
        assert code == 200 and d["token"] == TEST_TOKEN, "local no-Origin /token refused"
        code, d = http(port, "GET", "/token", headers={"Origin": "http://127.0.0.1:9507"})
        assert code == 200 and d["token"] == TEST_TOKEN, "loopback-Origin /token refused"
        code, _ = http(port, "GET", "/token", headers={"Origin": "https://evil.example"})
        assert code == 403, "remote-Origin /token was NOT refused"

        # --- side-effecting GETs refuse without/with-wrong token, never spawn.
        for path in ("/open?app=terminal", f"/list?path=", f"/read?path="):
            code, _ = http(port, "GET", path)
            assert code == 403, f"{path} answered without a token"
            code, _ = http(port, "GET", path, headers={"X-Bridge-Token": "wrong"})
            assert code == 403, f"{path} accepted a WRONG token"
        assert spawned == [], "a token-less /open still spawned a program"

        # --- with the token they work (header and query-param forms).
        code, d = http(port, "GET", "/open?app=terminal", headers=tok)
        assert code == 200 and d["ok"] and spawned, "tokened /open?app=terminal failed"
        code, d = http(port, "GET", "/list?path=", headers=tok)
        assert code == 200 and d["root"] == root, "tokened /list failed"
        code, d = http(port, "GET", f"/list?path=&token={TEST_TOKEN}")
        assert code == 200, "query-param token form failed"

        # --- POST /fs/*: loopback Origin alone is no longer enough.
        origin = {"Origin": "http://127.0.0.1:9507", "Content-Type": "application/json"}
        code, _ = http(port, "POST", "/fs/mkdir", headers=origin, body={"path": root, "name": "denied"})
        assert code == 403, "POST /fs/mkdir answered without a token"
        assert not os.path.exists(os.path.join(root, "denied")), "token-less POST still mutated files"
        code, d = http(port, "POST", "/fs/mkdir", headers={**origin, **tok}, body={"path": root, "name": "made"})
        assert code == 200 and d["ok"], "tokened POST /fs/mkdir failed"
        assert os.path.isdir(os.path.join(root, "made"))

        # --- pre-existing guards still hold behind the token.
        code, _ = http(port, "GET", "/open?app=terminal",
                       headers={**tok, "Host": "attacker.example"})
        assert code == 403, "Host-header (DNS-rebinding) guard regressed"
        code, _ = http(port, "POST", "/fs/mkdir",
                       headers={"Origin": "https://evil.example", "Content-Type": "application/json", **tok},
                       body={"path": root, "name": "evil"})
        assert code == 403, "remote-Origin POST guard regressed"
    finally:
        srv.shutdown()
        srv.server_close()


def main():
    root = os.path.realpath(tempfile.mkdtemp())
    outside = os.path.realpath(tempfile.mkdtemp())
    with open(os.path.join(outside, "secret.txt"), "w") as f:
        f.write("SECRET")
    # A symlink UNDER the root that points outside it.
    os.symlink(outside, os.path.join(root, "escape"))
    with open(os.path.join(root, "ok.txt"), "w") as f:
        f.write("inside")

    b = load_bridge(root)

    assert b.FILES_ROOT == root, f"FILES_ROOT not canonicalized: {b.FILES_ROOT}"

    # _confined must REJECT every escape.
    assert b._confined(os.path.join(root, "..", os.path.basename(outside))) is None, \
        "`..` traversal was not blocked"
    assert b._confined("/etc") is None, "absolute-outside path was not blocked"
    assert b._confined("~/../../etc") is None, "expanduser + traversal was not blocked"
    assert b._confined(os.path.join(root, "escape", "secret.txt")) is None, \
        "SYMLINK ESCAPE was not blocked (realpath confinement missing)"

    # _confined must ACCEPT paths genuinely under the root.
    assert b._confined(os.path.join(root, "ok.txt")) == os.path.join(root, "ok.txt"), \
        "a legitimate in-root path was rejected"
    assert b._confined(root) == root, "the root itself was rejected"

    # open_path must DENY (and never spawn) for out-of-root targets.
    ok, how = b.open_path("/etc")
    assert ok is False and how == "denied", f"open_path did not deny /etc: {ok}, {how}"
    ok, how = b.open_path(os.path.join(root, "escape", "secret.txt"))
    assert ok is False and how == "denied", f"open_path did not deny a symlink escape: {ok}, {how}"

    run_http_token_tests(b, root)

    print("ALL BRIDGE CONFINEMENT + TOKEN TESTS PASSED")


if __name__ == "__main__":
    main()
