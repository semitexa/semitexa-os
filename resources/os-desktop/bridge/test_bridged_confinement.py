#!/usr/bin/env python3
"""
Security regression test for the semitexa-bridge path confinement.

The bridge is HTTP-reachable from the browser, so /open, /list and /read must
never resolve a path outside SEMITEXA_FILES_ROOT — not via `..`, not via an
absolute path, and not via a symlink under the root that points outside it.
open_path() (which launches an editor / file manager) must additionally be
confined, or any web page could drive it to open arbitrary host files.

Run: python3 test_bridged_confinement.py
"""
import os
import tempfile
import importlib.util


def load_bridge(root):
    os.environ["SEMITEXA_FILES_ROOT"] = root
    here = os.path.dirname(os.path.abspath(__file__))
    spec = importlib.util.spec_from_file_location("bridged", os.path.join(here, "bridged.py"))
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


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

    print("ALL BRIDGE CONFINEMENT TESTS PASSED")


if __name__ == "__main__":
    main()
