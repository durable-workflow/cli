#!/usr/bin/env bash
# Regenerate src/catalog.json from the pinned 2.x reference CLI. The catalog
# is the 2.x command surface (names, aliases, descriptions, usage lines and
# option definitions) that command lookup and usage errors must match.
set -euo pipefail

crate="$(cd "$(dirname "$0")/.." && pwd)"
reference="$crate/target/dw-reference"
[[ -x "$reference" ]] || "$crate/parity/fetch-reference.sh" >/dev/null

DW_CONFIG_HOME="$(mktemp -d)" "$reference" list --format=json | python3 -c '
import json, sys
listing = json.load(sys.stdin)
commands = []
for command in listing["commands"]:
    usage = command["usage"]
    commands.append({
        "name": command["name"],
        "description": command["description"],
        "synopsis": usage[0],
        "aliases": usage[1:],
        "hidden": command["hidden"],
        "definition": command["definition"],
    })
json.dump({"source": "dw " + listing["application"]["version"].split(" ")[0], "commands": commands},
          sys.stdout, indent=1, sort_keys=False)
print()
' > "$crate/src/catalog.json"
echo "wrote $crate/src/catalog.json"
