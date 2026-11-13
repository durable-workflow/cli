#!/usr/bin/env bash
set -euo pipefail
umask 077
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
metadata="$(mktemp -d "${RUNNER_TEMP:?RUNNER_TEMP is required}/cli-phar-setup.XXXXXX")"
trap 'rm -rf -- "$metadata"' EXIT
: > "$metadata/environment"
: > "$metadata/path"
# Reuse the accepted build/ancestry source without changing the admitted test
# profile. Its private outputs are parsed as data, never sourced as shell code.
GITHUB_ENV="$metadata/environment" GITHUB_PATH="$metadata/path" \
    bash "$root/scripts/ci/setup-php-container.sh" 8.4
bindings="$(python3 -I -S -B - "$metadata/environment" "$metadata/path" "$root" <<'PY'
from pathlib import Path
import re,sys
lines = Path(sys.argv[1]).read_text().splitlines()
assert len(lines) == 6
values = dict(line.split('=', 1) for line in lines)
assert set(values) == {'CLI_PHP_CI_IMAGE','CLI_PHP_CI_ROOT','CLI_PHP_CI_STATE','COMPOSER_HOME','COMPOSER_CACHE_DIR','TMPDIR'}
assert values['CLI_PHP_CI_ROOT'] == sys.argv[3]
assert re.fullmatch(r'sha256:[0-9a-f]{64}', values['CLI_PHP_CI_IMAGE'])
state = values['CLI_PHP_CI_STATE']
assert values['COMPOSER_HOME'] == state+'/composer-home'
assert values['COMPOSER_CACHE_DIR'] == state+'/composer-home/cache'
assert values['TMPDIR'] == state+'/tmp'
assert Path(sys.argv[2]).read_text() == state+'/bin\n'
print(values['CLI_PHP_CI_IMAGE'])
print(state)
PY
)"
mapfile -t fields <<< "$bindings"
bash "$root/scripts/ci/prepare-php-phar-container.sh" "${fields[0]}" "${fields[1]}"
cat "$metadata/environment" >> "${GITHUB_ENV:?GITHUB_ENV is required}"
cat "$metadata/path" >> "${GITHUB_PATH:?GITHUB_PATH is required}"
