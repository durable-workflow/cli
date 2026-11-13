"""Literal -c bootstrap: obtain reviewed dispatcher bytes from a frozen Git object."""
import hashlib
import os
from pathlib import Path
import re
import subprocess
import sys

if sys.argv[0] != "-c":
    raise SystemExit("Use the reviewed literal -c Git bootstrap, not a host pathname")
commit = sys.argv.pop(1)
if not re.fullmatch(r"[0-9a-f]{40}", commit, re.ASCII):
    raise ValueError("bootstrap requires one reviewed full Git commit")
relative = "scripts/acceptance/command-keys/run_cli_216.py"
expected = "443fb73678de46ae6d52e2b388f23d1e6b0adf5aa2bc3fc85720c427718c9c7a"
data = subprocess.check_output(["/usr/bin/git", "--no-replace-objects", "show", commit + ":" + relative],
                               env={"PATH": os.defpath}, timeout=10)
if hashlib.sha256(data).hexdigest() != expected:
    raise ValueError("reviewed dispatcher Git bytes differ")
context = {"__name__": "__main__", "__file__": str(Path.cwd() / relative),
           "BOOTSTRAP_LAUNCHER_SHA256": expected, "BOOTSTRAP_SOURCE_COMMIT": commit}
exec(compile(data, context["__file__"], "exec"), context)
