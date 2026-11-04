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
download="$(mktemp "$crate/target/dw-reference.XXXXXX")"
trap 'rm -f "$download"' EXIT
curl --proto '=https' --tlsv1.2 -fsSL --retry 3 -o "$download" \
  "https://github.com/durable-workflow/cli/releases/download/${version}/${asset}"
# Only a verified download replaces the reference.
echo "${sha256}  ${download}" | sha256sum -c -
chmod +x "$download"
mv "$download" "$output"
trap - EXIT
"$output" --version
