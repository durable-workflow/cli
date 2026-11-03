#!/usr/bin/env python3
"""Compare the Rust dw against the 2.x dw, command by command.

Each case runs once per binary in a fresh config directory. The harness
compares the exit code, stdout (JSON compared as data after normalizing
times, ids, the config path and transport-specific error text), the stderr
category, and the config file the case leaves behind.

Usage:
  parity.py --reference /path/to/dw-2.x --candidate /path/to/dw \
      [--server php=http://127.0.0.1:18080 --server native=http://127.0.0.1:18081] \
      [--token TOKEN] [--report report.json]

Server cases run once per --server. Exit status is 1 when any case differs.
"""

import argparse
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))

TIME = re.compile(r"\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?")
ULID = re.compile(r"\b[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}\b")
# Transport errors name the HTTP stack that failed; only the category is
# part of the contract.
TRANSPORT_EXIT_CODES = {3, 7}


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
    if lines and lines[0].strip() == "" and len(lines) > 2 and lines[1].strip() == "":
        return "usage"
    return "error"


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
        config_path = os.path.join(config_dir, "config.json")
        config_text = None
        if os.path.exists(config_path):
            with open(config_path, encoding="utf-8") as handle:
                config_text = normalize_text(handle.read(), config_dir)
        stdout_json = parse_json_lines(completed.stdout)
        return {
            "exit_code": completed.returncode,
            "stdout_json": normalize_value(stdout_json, config_dir) if stdout_json is not None else None,
            "stdout_text": None if stdout_json is not None else normalize_text(completed.stdout, config_dir),
            "stderr_category": stderr_category(completed.stderr),
            "stderr": completed.stderr,
            "config": config_text,
        }
    finally:
        shutil.rmtree(config_dir, ignore_errors=True)


def compare(reference, candidate, case):
    differences = []
    for field in ("exit_code", "stderr_category", "stdout_json", "config"):
        if reference[field] != candidate[field]:
            differences.append(field)
    if case.get("compare_text", True) and reference["stdout_text"] != candidate["stdout_text"]:
        differences.append("stdout_text")
    return differences


def expand_cases(cases, servers):
    for case in cases:
        if not case.get("server"):
            yield case
            continue
        for label, url in servers:
            expanded = dict(case)
            expanded["name"] = f"{case['name']} [{label}]"
            expanded["server_label"] = label
            expanded["vars"] = {"server": url}
            expanded["env"] = {**case.get("env", {}), "DURABLE_WORKFLOW_SERVER_URL": "{server}"} if case.get("server_env") else case.get("env", {})
            yield expanded


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--reference", required=True)
    parser.add_argument("--candidate", required=True)
    parser.add_argument("--cases", default=os.path.join(HERE, "cases.json"))
    parser.add_argument("--server", action="append", default=[], help="label=url, repeatable")
    parser.add_argument("--token")
    parser.add_argument("--report")
    parser.add_argument("--verbose", action="store_true")
    options = parser.parse_args()

    servers = [tuple(item.split("=", 1)) for item in options.server]
    with open(options.cases, encoding="utf-8") as handle:
        cases = json.load(handle)["cases"]

    env_base = {key: value for key, value in os.environ.items()
                if not key.startswith(("DURABLE_WORKFLOW_", "DW_"))}
    if options.token:
        env_base["DURABLE_WORKFLOW_AUTH_TOKEN"] = options.token

    results = []
    failed = 0
    for case in expand_cases(cases, servers):
        reference = run_case(options.reference, case, env_base)
        candidate = run_case(options.candidate, case, env_base)
        differences = compare(reference, candidate, case)
        expected = case.get("expected_difference", {}).get(case.get("server_label", ""), case.get("expected_difference", {}).get("*"))
        if not differences:
            status = "same"
        elif expected:
            status = "expected"
        else:
            status = "DIFFERENT"
            failed += 1
        print(f"{status:9}  {case['name']}" + (f"  ({', '.join(differences)})" if differences else ""))
        if status == "expected":
            print(f"    intentional: {expected}")
        if differences and options.verbose:
            for field in differences:
                print(f"    reference {field}: {json.dumps(reference[field])[:600]}")
                print(f"    candidate {field}: {json.dumps(candidate[field])[:600]}")
        results.append({"case": case["name"], "argv": case["argv"], "differences": differences,
                        "intentional": expected if differences else None,
                        "reference": reference, "candidate": candidate})

    intentional = sum(1 for result in results if result["intentional"])
    print(f"\n{len(results) - failed - intentional}/{len(results)} cases match, {intentional} intentional differences, {failed} unexpected.")
    if options.report:
        with open(options.report, "w", encoding="utf-8") as handle:
            json.dump({"cases": results}, handle, indent=2)
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
