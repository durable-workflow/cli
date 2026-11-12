#!/usr/bin/env python3
"""Run the batch commands (cli#60) against the published Servers.

Uses the Servers parity/start-servers.sh started (DW_PARITY_SERVERS, with
DW_PARITY_TOKEN). On each Server it starts workflows of a fresh type, then:

- batch:cancel selects them, records a manifest, and acknowledges each one;
  batch:show agrees offline, and batch:resume finds nothing to resume;
- batch:signal acknowledges a second set;
- a third set is cancelled with --rate=1 --deadline=2, which stops partway
  with the rest pending and nothing unknown; batch:resume completes it.

Exit status is 1 when any check fails.

Usage, from the crate directory:
  export DW_PARITY_TOKEN=parity-token
  export DW_PARITY_SERVERS="$(parity/start-servers.sh)"
  python3 parity/live_batch.py [--candidate target/debug/dw]
"""

import argparse
import json
import os
import secrets
import shutil
import subprocess
import sys
import tempfile

CRATE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from parity import configured_servers  # noqa: E402


class Check:
    def __init__(self, binary, label, url, env):
        self.binary, self.label, self.url, self.env = binary, label, url, env
        self.failures = []

    def run(self, *args):
        # batch:show reads only the manifest and takes no connection options.
        server = [] if args and args[0] == "batch:show" else [f"--server={self.url}"]
        completed = subprocess.run([self.binary, *args, *server], env=self.env,
                                   stdin=subprocess.DEVNULL, capture_output=True, text=True, timeout=180)
        return completed

    def json(self, *args):
        completed = self.run(*args)
        try:
            return completed.returncode, json.loads(completed.stdout)
        except json.JSONDecodeError:
            return completed.returncode, {"stdout": completed.stdout, "stderr": completed.stderr}

    def expect(self, condition, what, detail=None):
        if not condition:
            self.failures.append(f"{what}: {json.dumps(detail)[:600] if detail is not None else ''}")

    def start(self, kind, count):
        workflow_type = f"parity.batch{secrets.token_hex(6)}{kind}"
        for index in range(count):
            code, body = self.json("workflow:start", f"--type={workflow_type}", "--task-queue=parity",
                                   f"--workflow-id={workflow_type}-{index}", "--json")
            self.expect(code == 0, f"start {workflow_type}-{index}", body)
        return workflow_type

    def selection(self, workflow_type):
        return [f"--query=WorkflowType = '{workflow_type}'", f"--type={workflow_type}"]


def scenario(check, manifests):
    # Cancel a set, then inspect and resume it.
    workflow_type = check.start("c", 5)
    manifest = os.path.join(manifests, f"{check.label}-cancel.jsonl")
    code, summary = check.json("batch:cancel", *check.selection(workflow_type), f"--manifest={manifest}",
                               "--reason=parity", "--yes", "--json")
    check.expect(code == 0 and summary.get("complete") is True and summary.get("counts", {}).get("acknowledged") == 5,
                 "batch:cancel acknowledges every target", summary)
    code, shown = check.json("batch:show", manifest, "--json")
    check.expect(code == 0 and shown.get("counts") == summary.get("counts"), "batch:show agrees", shown)
    code, resumed = check.json("batch:resume", manifest, "--yes", "--json")
    check.expect(code == 0 and resumed.get("counts") == summary.get("counts") and resumed.get("stopped") is None,
                 "batch:resume finds nothing to resume", resumed)
    mode = os.stat(manifest).st_mode & 0o777
    check.expect(mode == 0o600, "the manifest is private", oct(mode))

    # Signal a second set.
    workflow_type = check.start("s", 3)
    manifest = os.path.join(manifests, f"{check.label}-signal.jsonl")
    code, summary = check.json("batch:signal", "parity-go", *check.selection(workflow_type), f"--manifest={manifest}",
                               "--input=[\"live-batch\"]", "--yes", "--json")
    check.expect(code == 0 and summary.get("counts", {}).get("acknowledged") == 3,
                 "batch:signal acknowledges every target", summary)

    # Stop partway on a deadline, then resume.
    workflow_type = check.start("d", 6)
    manifest = os.path.join(manifests, f"{check.label}-deadline.jsonl")
    code, summary = check.json("batch:cancel", *check.selection(workflow_type), f"--manifest={manifest}",
                               "--rate=1", "--deadline=2", "--yes", "--json")
    counts = summary.get("counts", {})
    check.expect(code == 1 and (summary.get("stopped") or {}).get("reason") == "deadline"
                 and counts.get("unknown") == 0 and counts.get("acknowledged", 0) >= 1 and counts.get("pending", 0) >= 1,
                 "a deadline stops partway with the rest pending", summary)
    code, resumed = check.json("batch:resume", manifest, "--yes", "--json")
    check.expect(code == 0 and resumed.get("complete") is True and resumed.get("counts", {}).get("acknowledged") == 6,
                 "batch:resume completes after the deadline", resumed)


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--candidate", default=os.path.join(CRATE, "target", "debug", "dw"))
    options = parser.parse_args()
    servers = configured_servers()
    if not servers:
        raise SystemExit("Set DW_PARITY_SERVERS (parity/start-servers.sh prints it).")
    failed = 0
    for label, url in servers:
        config = tempfile.mkdtemp(prefix="dw-live-batch-")
        try:
            env = {key: value for key, value in os.environ.items() if not key.startswith(("DURABLE_WORKFLOW_", "DW_"))}
            env.update({"DW_CONFIG_HOME": config, "DURABLE_WORKFLOW_AUTH_TOKEN": os.environ.get("DW_PARITY_TOKEN", "")})
            check = Check(os.path.abspath(options.candidate), label, url, env)
            scenario(check, config)
            if check.failures:
                failed += 1
                print(f"FAILED  {label}")
                for failure in check.failures:
                    print(f"    {failure}")
            else:
                print(f"passed  {label}: cancel 5/5, show and resume agree, signal 3/3, deadline stop then resume 6/6")
        finally:
            shutil.rmtree(config, ignore_errors=True)
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
