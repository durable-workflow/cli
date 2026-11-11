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
import copy
import html
import http.server
import json
import os
import re
import secrets
import shutil
import subprocess
import sys
import tempfile
import threading
import urllib.parse

CRATE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CASES = os.path.join(CRATE, "parity", "cases.json")
REFERENCE = os.path.join(CRATE, "target", "dw-reference")
CANDIDATES = {
    "debug": os.path.join(CRATE, "target", "debug", "dw"),
    "release": os.path.join(CRATE, "target", "release", "dw"),
}
REPORT = os.path.join(CRATE, "target", "parity-report.json")
# Real cluster info from published PHP Server 2.11.8, served by the mock
# Server with a case's patch applied.
MOCK_CLUSTER_INFO = os.path.join(CRATE, "src", "compat", "fixtures", "cluster-info-php-2.11.8.json")

TIME = re.compile(r"\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[0-9.:+Z-]*")
ULID = re.compile(r"\b[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}\b")
UUID = re.compile(r"\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b")
CROCKFORD = "0123456789ABCDEFGHJKMNPQRSTVWXYZ"


def unique_id():
    """A fresh ULID-shaped value. Server cases that create state use
    {unique} so the reference and candidate runs touch different
    resources; the value normalizes to <id> like any ULID."""
    return "".join(secrets.choice(CROCKFORD) for _ in range(26))
VERSION_LINE = re.compile(r"dw \S+ \(commit [^,]+, built [^)]+\)")
BUILD_IDENTITY = re.compile(r"\(commit [^,]+, built [^)]+\)")
MARKDOWN_TITLE = re.compile(r"^(dw <version>)\n=+$", re.M)
TRANSPORT_LINE = re.compile(r"^(Server unreachable|Request timed out): .*$", re.M)
DOCTOR_TRANSPORT_LINE = re.compile(r"^(  Error: )(Server unreachable|Request timed out): .*$", re.M)
LOOPBACK_PORT = re.compile(r"127\.0\.0\.1:\d+")
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


def own_identity(binary):
    """Build metadata from this binary, rather than a different release."""
    output = subprocess.run([binary, "--version"], capture_output=True, text=True, timeout=60, check=False).stdout
    match = re.match(r"dw (\S+) \(commit ([^,]+), built ([^)]+)\)", output)
    return dict(zip(("version", "commit", "build_date"), match.groups())) if match else {}


def normalize_doctor_identity(stdout_json, stdout_text, identity):
    """Normalize only metadata that agrees with the binary's --version.

    A wrong diagnostic commit or build date remains a comparison failure.
    """
    for key, label in (("commit", "Commit"), ("build_date", "Built")):
        if key not in identity:
            continue
        replacement = f"<{key}>"
        stdout_text = re.sub(r"^  " + label + ": " + re.escape(identity[key]) + r"$",
                             f"  {label}: {replacement}", stdout_text, flags=re.M)
        for item in stdout_json or []:
            if isinstance(item, dict) and isinstance(item.get("dw"), dict) and item["dw"].get(key) == identity[key]:
                item["dw"][key] = replacement
    return stdout_json, stdout_text


def normalize_text(text, config_dir, version=None):
    text = text.replace(config_dir, "<config>")
    text = re.sub(re.escape(os.path.join(CRATE, "target", "parity-bin")) + r"/(reference|candidate)/", "<bin>/", text)
    if version:
        text = VERSION_LINE.sub("dw <version>", text)
        text = re.sub(r"(?<![\w.])" + re.escape(version) + r"(?![\w.-])", "<cli-version>", text)
    text = VERSION_LINE.sub("dw <version>", text)
    text = BUILD_IDENTITY.sub("(commit <commit>, built <date>)", text)
    text = MARKDOWN_TITLE.sub(r"\1\n<underline>", text)
    text = TIME.sub("<time>", text)
    text = TRANSPORT_LINE.sub("<transport error>", text)
    text = DOCTOR_TRANSPORT_LINE.sub(r"\1<transport error>", text)
    text = LOOPBACK_PORT.sub("127.0.0.1:<port>", text)
    return UUID.sub("<id>", ULID.sub("<id>", text))


def mask_fields(value, fields):
    """Replace the values of the named keys, at any depth. A case lists only
    fields derived from its own {unique} input, such as a Server-computed
    request fingerprint, so they cannot match across the two runs."""
    if isinstance(value, dict):
        return {key: "<masked>" if key in fields else mask_fields(item, fields) for key, item in value.items()}
    if isinstance(value, list):
        return [mask_fields(item, fields) for item in value]
    return value


def normalize_value(value, config_dir, version=None):
    if isinstance(value, dict):
        normalized = {key: normalize_value(item, config_dir, version) for key, item in value.items()}
        if normalized.get("exit_code") in TRANSPORT_EXIT_CODES and isinstance(normalized.get("error"), str):
            normalized["error"] = "<transport error>"
        return normalized
    if isinstance(value, list):
        return [normalize_value(item, config_dir, version) for item in value]
    if isinstance(value, str):
        return normalize_text(value, config_dir, version)
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


def run_case(binary, case, env_base, identity=None):
    identity = identity or {}
    version = identity.get("version")
    case = dict(case, vars={**case.get("vars", {}), "unique": unique_id()})
    config_dir = tempfile.mkdtemp(prefix="dw-parity-")
    try:
        env = dict(env_base)
        env["DW_CONFIG_HOME"] = config_dir
        for key, value in case.get("env", {}).items():
            env[key] = substitute(value, case)
        project = None
        if "project" in case:
            project = prepare_project(config_dir, case["project"], env)
        for step in case.get("setup", []):
            subprocess.run([binary] + [substitute(arg, case) for arg in step], env=env,
                           stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=60, check=False)
        argv = [substitute(arg, case) for arg in case["argv"]]
        # No terminal on stdin, so neither CLI may prompt.
        completed = subprocess.run([binary] + argv, env=env, stdin=subprocess.DEVNULL, capture_output=True,
                                   text=True, timeout=60, check=False, cwd=project)
        stdout_json = parse_json_lines(completed.stdout)
        stdout_text = completed.stdout
        if argv and argv[0] == "doctor":
            stdout_json, stdout_text = normalize_doctor_identity(stdout_json, stdout_text, identity)
        if stdout_json is not None and case.get("normalize_fields"):
            stdout_json = mask_fields(stdout_json, set(case["normalize_fields"]))
        return {
            "exit_code": completed.returncode,
            "stdout_json": normalize_value(stdout_json, config_dir, version) if stdout_json is not None else None,
            "stdout_text": None if stdout_json is not None else normalize_text(stdout_text, config_dir, version),
            "stderr_category": stderr_category(completed.stderr),
            "stderr": normalize_text(completed.stderr, config_dir, version),
            "config": read_config(config_dir),
        }
    finally:
        shutil.rmtree(config_dir, ignore_errors=True)


def prepare_project(config_dir, options, env):
    """Disposable Laravel stand-in with fake external tools; no services run."""
    project = os.path.join(config_dir, "project with spaces" if options.get("spaces") else "project")
    tool_dir = os.path.join(project, "bin")
    os.makedirs(tool_dir)
    if options.get("artisan", True):
        with open(os.path.join(project, "artisan"), "w", encoding="utf-8") as handle:
            handle.write("fixture project\n")
    for tool, default in (("php", True), ("docker", False)):
        if options.get(tool, default):
            path = os.path.join(tool_dir, tool)
            shutil.copyfile(os.path.join(CRATE, "parity", "fixtures", "start-dev", tool), path)
            os.chmod(path, 0o700)
    env["PATH"] = tool_dir
    return project


def normalize_schema_manifest(text, case, side):
    """Allow only reviewed, directional schema hash updates; retain all other bytes."""
    if case.get("argv") != ["schema:manifest"] or not isinstance(text, str):
        return text
    try:
        manifest = json.loads(text)
    except ValueError:
        return text
    if (not isinstance(manifest, dict)
            or manifest.get("schema") != "durable-workflow.cli.output-schema-manifest"
            or manifest.get("version") != 3
            or not isinstance(manifest.get("commands"), dict)):
        return text
    for command, update in case.get("schema_sha256_updates", {}).items():
        digest = update[side]
        entry = manifest["commands"].get(command)
        if (isinstance(entry, dict) and entry.get("sha256") == digest
                and text.count(digest) == 1):
            text = text.replace(digest, f"<reviewed-schema-sha256:{command}>")
    return text


def compare(reference, candidate, case):
    fields = ["exit_code", "stderr_category", "stderr", "stdout_json", "config"]
    if case.get("compare_text", True):
        fields.append("stdout_text")
    differences = []
    for field in fields:
        left, right = reference[field], candidate[field]
        if field in {"stdout_text", "stdout_json"}:
            left = normalize_catalog_metadata(left, case, "reference")
            right = normalize_catalog_metadata(right, case, "candidate")
        if field == "stdout_text" and case.get("schema_sha256_updates"):
            left = normalize_schema_manifest(left, case, "reference")
            right = normalize_schema_manifest(right, case, "candidate")
        if left != right:
            differences.append(field)
    return differences


def normalize_catalog_metadata(value, case, side, updates=None):
    """Reconcile exact reviewed help fields on descriptor surfaces only."""
    argv = case.get("argv", [])
    if argv and argv[0] not in {"list", "help"} and not any(flag in argv for flag in ["--help", "-h"]):
        return value
    if updates is None:
        with open(os.path.join(CRATE, "parity", "catalog-updates.json"), encoding="utf-8") as handle:
            updates = json.load(handle)

    def visit(item):
        if isinstance(item, list):
            return [visit(child) for child in item]
        if isinstance(item, dict):
            result = {key: visit(child) for key, child in item.items()}
            command = item.get("name")
            if (isinstance(command, str) and command in updates
                    and ("definition" in item or "--short" in argv)):
                for field, update in updates[command].items():
                    if item.get(field) == update[side]:
                        result[field] = f"<reviewed-catalog:{command}:{field}>"
            return result
        return item

    if isinstance(value, str):
        for command, fields in updates.items():
            if command not in value:
                continue
            for field, update in fields.items():
                text = update[side]
                if not isinstance(text, str):
                    # A reviewed option definition reconciles only in JSON.
                    continue
                plain = re.sub(r"</?(?:info|comment)>", "", text)
                for rendered in dict.fromkeys([text, html.escape(text, quote=False), plain]):
                    if value.count(rendered) == 1:
                        value = value.replace(rendered, f"<reviewed-catalog:{command}:{field}>")
                        break
        return value
    return visit(value)


def patched_cluster_info(patches):
    """The fixture with each [path, value] patch applied; a null value deletes."""
    with open(MOCK_CLUSTER_INFO, encoding="utf-8") as handle:
        info = json.load(handle)
    for path, value in patches:
        target = info
        for key in path[:-1]:
            target = target[key]
        if value is None:
            target.pop(path[-1], None)
        else:
            target[path[-1]] = copy.deepcopy(value)
    return info


class MockServer:
    """A loopback Server answering /api/cluster/info and /api/health, plus
    any extra routes a case declares (such as a GitHub release API)."""

    def __init__(self, cluster_info, routes=None):
        body = json.dumps(cluster_info).encode()
        health = json.dumps({"status": "serving", "timestamp": "2026-01-01T00:00:00+00:00", "checks": {"database": "ok"}}).encode()
        extra = {}
        for path, route in (routes or {}).items():
            content = route.get("body", "")
            content = content if isinstance(content, str) else json.dumps(content)
            headers = {"Content-Type": route.get("content_type", "application/json"), **route.get("headers", {})}
            extra[path] = (route.get("method", "GET"), route.get("status", 200), headers,
                           content.encode(), route.get("echo_request", False))

        class Handler(http.server.BaseHTTPRequestHandler):
            def respond(self):
                path = self.path.split("?")[0]
                if path in extra and extra[path][0] == self.command:
                    _, status, headers, content, echo = extra[path]
                    if echo:
                        self.connection.settimeout(2)
                        length = int(self.headers.get("Content-Length", "0"))
                        if length < 0 or length > 65536:
                            self.send_error(413)
                            return
                        data = self.rfile.read(length)
                        content = json.dumps({"request": {"method": self.command,
                            "body": json.loads(data) if data else None}}).encode()
                    self.send_response(status)
                    for name, value in headers.items():
                        self.send_header(name, value)
                    self.end_headers()
                    self.wfile.write(content)
                    return
                payload = {"/api/cluster/info": body, "/api/health": health}.get(path) if self.command == "GET" else None
                self.send_response(200 if payload else 404)
                self.send_header("Content-Type", "application/json")
                self.send_header("X-Durable-Workflow-Control-Plane-Version", "2")
                self.end_headers()
                self.wfile.write(payload or b'{"message":"Not found."}')

            do_GET = respond
            do_POST = respond
            do_PUT = respond
            do_DELETE = respond

            def log_message(self, *args):
                # Keep request logs out of the compared stderr.
                return

        self.server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), Handler)
        self.url = f"http://127.0.0.1:{self.server.server_address[1]}"
        threading.Thread(target=self.server.serve_forever, daemon=True).start()

    def close(self):
        self.server.shutdown()
        self.server.server_close()


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


def intentional_reason(case, differences):
    """The recorded reason, only when every differing field is one it names."""
    reasons = case.get("expected_difference", {})
    expected = reasons.get(case.get("server_label", ""), reasons.get("*"))
    if expected and set(differences) <= set(expected["fields"]):
        return expected["reason"]
    return None


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


def help_cases():
    """`--help` for every 2.x command, ported or not, from the catalog."""
    with open(os.path.join(CRATE, "src", "catalog.json"), encoding="utf-8") as handle:
        names = [command["name"] for command in json.load(handle)["commands"]]
    cases = [{"name": f"--help for {name}", "argv": [name, "--help"]} for name in names]
    for case in cases:
        if case["argv"][0] == "runtime:check":
            case["expected_difference"] = {"*": {
                "fields": ["stdout_text"],
                "reason": "The Rust-native runtime:check help describes build identity and target instead of PHP extensions (cli#72).",
            }}
        if case["argv"][0] == "workflow:list-runs":
            case["expected_difference"] = {"*": {
                "fields": ["stdout_text"],
                "reason": "The help accurately describes one Server response page and an unconsumed cursor (cli#113).",
            }}
        if case["argv"][0] == "workflow:signal":
            case["expected_difference"] = {"*": {
                "fields": ["stdout_text"],
                "reason": "Signals carry PHP main's caller command key: --request-id and its help (cli#61, cli#72).",
            }}
    return cases


def invocation_path(binary, role):
    """A copy of the binary named `dw`. Help texts embed the executable's
    resolved path (Symfony's %command.full_name%), so both CLIs need the
    same file name; their directories are normalized."""
    directory = os.path.join(CRATE, "target", "parity-bin", role)
    os.makedirs(directory, exist_ok=True)
    copy_path = os.path.join(directory, "dw")
    if os.path.lexists(copy_path):
        os.remove(copy_path)
    shutil.copy2(binary, copy_path)
    return copy_path


def run_all(candidate_binary, servers, verbose, server_only=False):
    with open(CASES, encoding="utf-8") as handle:
        cases = json.load(handle)["cases"] + help_cases()
    if server_only:
        cases = [case for case in cases if case.get("server")]
    env_base = {key: value for key, value in os.environ.items() if not key.startswith(("DURABLE_WORKFLOW_", "DW_"))}
    token = os.environ.get("DW_PARITY_TOKEN")
    if token:
        env_base["DURABLE_WORKFLOW_AUTH_TOKEN"] = token

    reference_binary = invocation_path(REFERENCE, "reference")
    candidate_binary = invocation_path(candidate_binary, "candidate")
    identities = {reference_binary: own_identity(reference_binary), candidate_binary: own_identity(candidate_binary)}
    results = []
    for case in expand_cases(cases, servers):
        uses_mock = "mock" in case or "mock_routes" in case
        mock = MockServer(patched_cluster_info(case.get("mock", [])), case.get("mock_routes")) if uses_mock else None
        if mock:
            case = dict(case, vars={**case.get("vars", {}), "mock": mock.url})
        try:
            reference = run_case(reference_binary, case, env_base, identities[reference_binary])
            candidate = run_case(candidate_binary, case, env_base, identities[candidate_binary])
        finally:
            if mock:
                mock.close()
        differences = compare(reference, candidate, case)
        reason = intentional_reason(case, differences) if differences else None
        print_result(case, differences, reason, reference, candidate, verbose)
        results.append({"case": case["name"], "argv": case["argv"], "differences": differences,
                        "intentional": reason, "reference": reference, "candidate": candidate})
    return results


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--build", choices=sorted(CANDIDATES), default="debug")
    parser.add_argument("--candidate", help="compare this executable instead of a cargo build (a release asset)")
    parser.add_argument("--report", action="store_true", help="write target/parity-report.json")
    parser.add_argument("--verbose", action="store_true")
    parser.add_argument("--server-cases-only", action="store_true",
                        help="run only the cases that need DW_PARITY_SERVERS")
    options = parser.parse_args()

    candidate = os.path.abspath(options.candidate) if options.candidate else CANDIDATES[options.build]
    for binary in (REFERENCE, candidate):
        if not os.access(binary, os.X_OK):
            raise SystemExit(f"Missing executable {binary}. Run parity/fetch-reference.sh and cargo build first.")

    servers = configured_servers()
    if options.server_cases_only and not servers:
        raise SystemExit("--server-cases-only needs DW_PARITY_SERVERS.")
    results = run_all(candidate, servers, options.verbose, options.server_cases_only)
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
