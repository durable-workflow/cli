#!/usr/bin/env bash
# Regenerate src/completion.json from the pinned 2.x reference CLI: the
# values Symfony's shell completion suggests for every option and argument
# of every command, and the command list it offers before a command is
# typed. 2.x has no input-dependent completers, so these are fixed tables.
set -euo pipefail

crate="$(cd "$(dirname "$0")/.." && pwd)"
reference="$crate/target/dw-reference"
[[ -x "$reference" ]] || "$crate/parity/fetch-reference.sh" >/dev/null

DW_CONFIG_HOME="$(mktemp -d)" DW_REFERENCE="$reference" python3 -I - "$crate/src/catalog.json" > "$crate/src/completion.json" <<'PY'
import json, os, subprocess, sys

reference = os.environ["DW_REFERENCE"]
catalog = json.load(open(sys.argv[1], encoding="utf-8"))

def complete(shell, tokens, current):
    argv = [reference, "_complete", f"-s{shell}", "-a1", f"-c{current}"]
    argv += [f"-i{token}" for token in tokens]
    result = subprocess.run(argv, capture_output=True, text=True, check=True)
    return [line for line in result.stdout.split("\n") if line != ""]

# Before a command is typed: names (and aliases) with descriptions.
application = []
# The shell scripts never pass empty words; a cursor after the last word is
# index len(tokens).
for line in complete("zsh", ["dw"], 1):
    value, _, description = line.partition("\t")
    application.append({"value": value, "description": description})

values = {}
for command in catalog["commands"]:
    name = command["name"]
    definition = command["definition"]
    options = definition["options"]
    options = options.values() if isinstance(options, dict) else options
    found = {}
    for option in options:
        if not option["accept_value"] or option["name"].startswith("--no-") and option["name"][5:] in (o["name"][2:] for o in options):
            continue
        suggested = complete("bash", ["dw", name, option["name"] + "="], 2)
        if suggested:
            found.setdefault("options", {})[option["name"][2:]] = suggested
    arguments = definition["arguments"]
    arguments = list(arguments.values()) if isinstance(arguments, dict) else arguments
    for position, argument in enumerate(arguments):
        suggested = complete("bash", ["dw", name] + ["x"] * position, position + 2)
        if suggested:
            found.setdefault("arguments", {})[argument["name"]] = suggested
    if found:
        values[name] = found

json.dump({"source": catalog["source"], "application": application, "values": values},
          sys.stdout, indent=1, ensure_ascii=False)
print()
PY
echo "wrote $crate/src/completion.json"
