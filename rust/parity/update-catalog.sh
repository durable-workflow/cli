#!/usr/bin/env bash
# Regenerate src/catalog.json from the pinned 2.x reference CLI. The catalog
# is the 2.x command surface (names, aliases, descriptions, usage lines and
# option definitions) that command lookup and usage errors must match.
set -euo pipefail

crate="$(cd "$(dirname "$0")/.." && pwd)"
reference="$crate/target/dw-reference"
[[ -x "$reference" ]] || "$crate/parity/fetch-reference.sh" >/dev/null

# Help texts embed the invoked path (%command.full_name%). Store placeholders
# that the CLI fills from its own argv[0], never this machine's path.
DW_CONFIG_HOME="$(mktemp -d)" "$reference" list --format=json | DW_REFERENCE="$reference" DW_CATALOG_UPDATES="$crate/parity/catalog-updates.json" DW_CATALOG_ADDITIONS="$crate/parity/catalog-additions.json" python3 -c '
import json, os, sys
reference = os.environ["DW_REFERENCE"]

def placeholders(value):
    if isinstance(value, str):
        return value.replace(reference, "{dw}").replace(os.path.basename(reference), "{dw_name}")
    if isinstance(value, list):
        return [placeholders(item) for item in value]
    if isinstance(value, dict):
        return {key: placeholders(item) for key, item in value.items()}
    return value

listing = placeholders(json.load(sys.stdin))
with open(os.environ["DW_CATALOG_UPDATES"], encoding="utf-8") as handle:
    updates = json.load(handle)
commands = []
for command in listing["commands"]:
    usage = command["usage"]
    commands.append({
        "name": command["name"],
        "description": command["description"],
        "synopsis": usage[0],
        "aliases": usage[1:],
        "hidden": command["hidden"],
        "help": command["help"],
        "definition": command["definition"],
    })
    for field, update in updates.get(command["name"], {}).items():
        if commands[-1][field] != update["reference"]:
            raise SystemExit("Reference catalog metadata changed: " + command["name"] + ":" + field)
        commands[-1][field] = update["candidate"]
# Rust-only commands (cli#60, Server #577, Server #1131) go into their namespace in name order.
with open(os.environ["DW_CATALOG_ADDITIONS"], encoding="utf-8") as handle:
    additions = json.load(handle)["commands"]
for addition in additions:
    position = next((index for index, command in enumerate(commands)
                     if ":" in command["name"] and command["name"] > addition["name"]), len(commands))
    commands.insert(position, addition)
json.dump({"source": "dw " + listing["application"]["version"].split(" ")[0], "commands": commands},
          sys.stdout, indent=1, sort_keys=False)
print()
' > "$crate/src/catalog.json"
echo "wrote $crate/src/catalog.json"
