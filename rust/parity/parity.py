#!/usr/bin/env python3
"""Compare the Rust dw against the 2.x dw, command by command.

Each case runs once per binary in a fresh config directory. The harness
compares the exit code, stdout (JSON compared as data after normalizing
times, ids, the config path and transport-specific error text), the stderr
category, and the config file the case leaves behind.

Usage, from the crate directory:
  parity/fetch-reference.sh            # pinned 2.x CLI -> target/dw-reference
  cargo build
  python3 parity/parity.py [--build debug|release] [--report] [--verbose]

Server cases run when DW_PARITY_SERVERS names running Servers, for example
  DW_PARITY_SERVERS=php=http://127.0.0.1:18080,native=http://127.0.0.1:18081
with DW_PARITY_TOKEN as the auth token. The report, when asked for, is
written to target/parity-report.json. Exit status is 1 when any case differs
without a recorded reason.
"""

import argparse
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
import urllib.parse

CRATE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CASES = os.path.join(CRATE, "parity", "cases.json")
REFERENCE = os.path.join(CRATE, "target", "dw-reference")
CANDIDATES = {
    "debug": os.path.join(CRATE, "target", "debug", "dw"),
    "release": os.path.join(CRATE, "target", "release", "dw"),
}
REPORT = os.path.join(CRATE, "target", "parity-report.json")

TIME = re.compile(r"\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[0-9.:+Z-]*")
ULID = re.compile(r"\b[0-9A-Za-z]{26}\b")
SERVER_LABEL = re.compile(r"[a-z][a-z0-9-]{0,31}")
# Transport errors name the HTTP stack that failed; only the category is
# part of the contract.
TRANSPORT_EXIT_CODES = {3, 7}


def configured_servers():
    """Servers from DW_PARITY_SERVERS, validated and rebuilt from parts."""
    servers = []
    for item in filter(None, os.environ.get("DW_PARITY_SERVERS", "").split(",")):
        label, _, url = item.partition("=")
        parts = urllib.parse.urlsplit(url)
        if not SERVER_LABEL.fullmatch(label) or parts.scheme not in ("http", "https") or not parts.hostname:
            raise SystemExit(f"DW_PARITY_SERVERS entry is not label=http(s)://host[:port]: {item!r}")
        port = f":{parts.port}" if parts.port else ""
        servers.append((label, f"{parts.scheme}://{parts.hostname}{port}"))
    return servers


def substitute(text, case):
    for key, value in case.get("vars", {}).items():
        text = text.replace("{" + key + "}", value)
    return text


def normalize_text(text, config_dir):
    text = text.replace(config_dir, "<config>")
    text = TIME.sub("<time>", text)
    return ULID.sub("<id>", text)


def normalize_value(value, config_dir):
    if isinstance(value, dict):
        normalized = {key: normalize_value(item, config_dir) for key, item in value.items()}
        if normalized.get("exit_code") in TRANSPORT_EXIT_CODES and isinstance(normalized.get("error"), str):
            normalized["error"] = "<transport error>"
        return normalized
    if isinstance(value, list):
        return [normalize_value(item, config_dir) for item in value]
    if isinstance(value, str):
        return normalize_text(value, config_dir)
    return value


def parse_json_lines(stdout):
    lines = [line for line in stdout.splitlines() if line.strip()]
    if not lines:
        return None
    try:
        return [json.loads(line) for line in lines]
    except ValueError:
        return None


def stderr_category(stderr):
    """empty, usage (console usage error), or error (command error text)."""
    if not stderr.strip():
        return "empty"
    lines = stderr.splitlines()
    if len(lines) > 2 and lines[0].strip() == "" and lines[1].strip() == "":
        return "usage"
    return "error"


def read_config(config_dir):
    path = os.path.join(config_dir, "config.json")
    if not os.path.exists(path):
        return None
    with open(path, encoding="utf-8") as handle:
        return normalize_text(handle.read(), config_dir)


def run_case(binary, case, env_base):
    config_dir = tempfile.mkdtemp(prefix="dw-parity-")
    try:
        env = dict(env_base)
        env["DW_CONFIG_HOME"] = config_dir
        for key, value in case.get("env", {}).items():
            env[key] = substitute(value, case)
        for step in case.get("setup", []):
            subprocess.run([binary] + [substitute(arg, case) for arg in step], env=env,
                           stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=60, check=False)
        argv = [substitute(arg, case) for arg in case["argv"]]
        completed = subprocess.run([binary] + argv, env=env, capture_output=True, text=True, timeout=60, check=False)
        stdout_json = parse_json_lines(completed.stdout)
        return {
            "exit_code": completed.returncode,
            "stdout_json": normalize_value(stdout_json, config_dir) if stdout_json is not None else None,
            "stdout_text": None if stdout_json is not None else normalize_text(completed.stdout, config_dir),
            "stderr_category": stderr_category(completed.stderr),
            "stderr": completed.stderr,
            "config": read_config(config_dir),
        }
    finally:
        shutil.rmtree(config_dir, ignore_errors=True)


def compare(reference, candidate, case):
    fields = ["exit_code", "stderr_category", "stdout_json", "config"]
    if case.get("compare_text", True):
        fields.append("stdout_text")
    return [field for field in fields if reference[field] != candidate[field]]


def expand_cases(cases, servers):
    for case in cases:
        if not case.get("server"):
            yield case
            continue
        for label, url in servers:
            expanded = dict(case, name=f"{case['name']} [{label}]", server_label=label, vars={"server": url})
            if case.get("server_env"):
                expanded["env"] = {**case.get("env", {}), "DURABLE_WORKFLOW_SERVER_URL": "{server}"}
            yield expanded


def intentional_reason(case):
    reasons = case.get("expected_difference", {})
    return reasons.get(case.get("server_label", ""), reasons.get("*"))


def print_result(case, differences, reason, reference, candidate, verbose):
    if not differences:
        status = "same"
    elif reason:
        status = "expected"
    else:
        status = "DIFFERENT"
    suffix = f"  ({', '.join(differences)})" if differences else ""
    print(f"{status:9}  {case['name']}{suffix}")
    if status == "expected":
        print(f"    intentional: {reason}")
    if differences and verbose:
        for field in differences:
            print(f"    reference {field}: {json.dumps(reference[field])[:600]}")
            print(f"    candidate {field}: {json.dumps(candidate[field])[:600]}")


def run_all(candidate_binary, servers, verbose):
    with open(CASES, encoding="utf-8") as handle:
        cases = json.load(handle)["cases"]
    env_base = {key: value for key, value in os.environ.items() if not key.startswith(("DURABLE_WORKFLOW_", "DW_"))}
    token = os.environ.get("DW_PARITY_TOKEN")
    if token:
        env_base["DURABLE_WORKFLOW_AUTH_TOKEN"] = token

    results = []
    for case in expand_cases(cases, servers):
        reference = run_case(REFERENCE, case, env_base)
        candidate = run_case(candidate_binary, case, env_base)
        differences = compare(reference, candidate, case)
        reason = intentional_reason(case) if differences else None
        print_result(case, differences, reason, reference, candidate, verbose)
        results.append({"case": case["name"], "argv": case["argv"], "differences": differences,
                        "intentional": reason, "reference": reference, "candidate": candidate})
    return results


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--build", choices=sorted(CANDIDATES), default="debug")
    parser.add_argument("--report", action="store_true", help="write target/parity-report.json")
    parser.add_argument("--verbose", action="store_true")
    options = parser.parse_args()

    for binary in (REFERENCE, CANDIDATES[options.build]):
        if not os.access(binary, os.X_OK):
            raise SystemExit(f"Missing executable {binary}. Run parity/fetch-reference.sh and cargo build first.")

    results = run_all(CANDIDATES[options.build], configured_servers(), options.verbose)
    unexpected = sum(1 for result in results if result["differences"] and not result["intentional"])
    intentional = sum(1 for result in results if result["intentional"])
    print(f"\n{len(results) - unexpected - intentional}/{len(results)} cases match, "
          f"{intentional} intentional differences, {unexpected} unexpected.")
    if options.report:
        with open(REPORT, "w", encoding="utf-8") as handle:
            json.dump({"cases": results}, handle, indent=2)
    return 1 if unexpected else 0


if __name__ == "__main__":
    sys.exit(main())
