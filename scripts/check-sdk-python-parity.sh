#!/usr/bin/env bash
# Fail if any control-plane parity fixture drifts between this repo and sdk-python.
#
# Both the CLI and the Python SDK carry a copy of each shared parity fixture
# under tests/fixtures/control-plane/. The copies must stay byte-identical so
# that neither side can silently change the shared wire contract or the
# opposite side's language projection.
#
# Usage:
#   scripts/check-sdk-python-parity.sh <sdk-python checkout>
#
# Exits 0 when every fixture that exists in both repos is byte-identical.
# Exits 1 with a unified diff when any fixture drifts.

set -euo pipefail

if [[ $# -lt 1 ]]; then
  echo "usage: $0 <path-to-sdk-python-checkout>" >&2
  exit 2
fi

sdk_root="$1"
cli_root="$(cd "$(dirname "$0")/.." && pwd)"

cli_dir="$cli_root/tests/fixtures/control-plane"
sdk_dir="$sdk_root/tests/fixtures/control-plane"

if [[ ! -d "$cli_dir" ]]; then
  echo "CLI fixtures directory not found: $cli_dir" >&2
  exit 1
fi
if [[ ! -d "$sdk_dir" ]]; then
  echo "SDK-Python fixtures directory not found: $sdk_dir" >&2
  exit 1
fi

compared=0
drifted=0
divergent_files=()

while IFS= read -r -d '' cli_file; do
  name="$(basename "$cli_file")"
  sdk_file="$sdk_dir/$name"
  if [[ ! -e "$sdk_file" ]]; then
    continue
  fi
  compared=$((compared + 1))
  if ! cmp -s "$cli_file" "$sdk_file"; then
    drifted=$((drifted + 1))
    divergent_files+=("$name")
  fi
done < <(find "$cli_dir" -maxdepth 1 -name '*-parity.json' -type f -print0 | sort -z)

echo "Compared $compared shared parity fixture(s) between CLI and SDK-Python."

if [[ $drifted -eq 0 ]]; then
  echo "All shared fixtures are byte-identical."
  exit 0
fi

echo >&2
echo "$drifted fixture(s) drifted:" >&2
echo >&2
for name in "${divergent_files[@]}"; do
  echo "--- $name ---" >&2
  diff -u "$cli_dir/$name" "$sdk_dir/$name" || true
  echo >&2
done >&2
echo "Reconcile the fixtures so both repos advertise the same control-plane contract." >&2
exit 1
