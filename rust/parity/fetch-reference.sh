#!/usr/bin/env bash
# Download the pinned 2.x reference CLI to target/dw-reference and verify
# its checksum. parity.py reads it from there.
set -euo pipefail

crate="$(cd "$(dirname "$0")/.." && pwd)"
output="$crate/target/dw-reference"
read -r version asset sha256 < <(python3 -c '
import json, sys
pin = json.load(open(sys.argv[1]))
print(pin["version"], pin["asset"], pin["sha256"])
' "$crate/parity/reference.json")

mkdir -p "$crate/target"
curl --proto '=https' --tlsv1.2 -fsSL --retry 3 -o "$output" \
  "https://github.com/durable-workflow/cli/releases/download/${version}/${asset}"
echo "${sha256}  ${output}" | sha256sum -c -
chmod +x "$output"
"$output" --version
