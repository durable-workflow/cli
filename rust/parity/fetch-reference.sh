#!/usr/bin/env bash
# Download the pinned 2.x reference CLI and verify its checksum.
# Usage: fetch-reference.sh <output-path>
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
output="${1:?usage: fetch-reference.sh <output-path>}"
read -r version asset sha256 < <(python3 -c '
import json, sys
pin = json.load(open(sys.argv[1]))
print(pin["version"], pin["asset"], pin["sha256"])
' "$here/reference.json")

curl -fsSL --retry 3 -o "$output" "https://github.com/durable-workflow/cli/releases/download/${version}/${asset}"
echo "${sha256}  ${output}" | sha256sum -c -
chmod +x "$output"
"$output" --version
