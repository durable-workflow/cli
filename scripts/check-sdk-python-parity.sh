#!/usr/bin/env bash
# Fail if any control-plane parity fixture drifts between this repo and sdk-python.
#
# Both the CLI and the Python SDK carry the same set of shared parity fixtures
# under tests/fixtures/control-plane/. The copies must stay byte-identical so
# that neither side can silently add an operation, change the shared wire
# contract, or change the opposite side's language projection.
#
# Usage:
#   scripts/check-sdk-python-parity.sh <sdk-python checkout>
#
# Exits 0 when both repos carry the same byte-identical fixtures.
# Exits 1 with a missing-file report or unified diff when any fixture drifts.

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
missing=0
divergent_files=()
cli_only=()
sdk_only=()

while IFS= read -r -d '' cli_file; do
  name="$(basename "$cli_file")"
  sdk_file="$sdk_dir/$name"
  if [[ ! -e "$sdk_file" ]]; then
    missing=$((missing + 1))
    cli_only+=("$name")
    continue
  fi
  compared=$((compared + 1))
  if ! cmp -s "$cli_file" "$sdk_file"; then
    drifted=$((drifted + 1))
    divergent_files+=("$name")
  fi
done < <(find "$cli_dir" -maxdepth 1 -name '*-parity.json' -type f -print0 | sort -z)

while IFS= read -r -d '' sdk_file; do
  name="$(basename "$sdk_file")"
  cli_file="$cli_dir/$name"
  if [[ ! -e "$cli_file" ]]; then
    missing=$((missing + 1))
    sdk_only+=("$name")
  fi
done < <(find "$sdk_dir" -maxdepth 1 -name '*-parity.json' -type f -print0 | sort -z)

echo "Compared $compared shared parity fixture(s) between CLI and SDK-Python."

if [[ $missing -eq 0 && $drifted -eq 0 ]]; then
  echo "All shared fixtures are byte-identical."
  exit 0
fi

if [[ $missing -gt 0 ]]; then
  echo >&2
  echo "Parity fixture filename drift detected:" >&2
  echo >&2
  if [[ ${#cli_only[@]} -gt 0 ]]; then
    echo "Present in CLI only:" >&2
    for name in "${cli_only[@]}"; do
      echo "  - tests/fixtures/control-plane/$name" >&2
    done
    echo >&2
  fi
  if [[ ${#sdk_only[@]} -gt 0 ]]; then
    echo "Present in SDK-Python only:" >&2
    for name in "${sdk_only[@]}"; do
      echo "  - tests/fixtures/control-plane/$name" >&2
    done
    echo >&2
  fi
fi

if [[ $drifted -eq 0 ]]; then
  echo "Reconcile the fixture set so both repos advertise the same shared control-plane operations." >&2
  exit 1
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
