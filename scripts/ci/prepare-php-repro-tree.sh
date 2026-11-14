#!/usr/bin/env bash
# Only the host prepares archived controls. PHP receives a read-only tree.
set -euo pipefail
umask 077
source_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
state="${CLI_PHP_CI_STATE:?PHP CI state is required}"
tree="${1:?Usage: prepare-php-repro-tree.sh ARCHIVED_TREE}"
[[ "${CLI_PHP_CI_REPRO:-}" = 1 && "${CLI_PHP_CI_ROOT:-}" = "$source_root" ]]
[[ -d "$state" && ! -L "$state" && -O "$state" && "$(realpath "$state")" = "$state" ]]
[[ -d "$tree" && ! -L "$tree" && -O "$tree" && "$(realpath "$tree")" = "$tree" ]]
case "$tree" in "${RUNNER_TEMP:?RUNNER_TEMP is required}"/dw-cli-repro.*/source.[12]) ;; *) exit 1 ;; esac
case "$tree/" in "$state/"*|"$source_root/"*) echo 'Reproducibility trees must stay outside runtime writable data and source.' >&2; exit 1 ;; *) ;; esac
for directory in "$tree/vendor" "$tree/build"; do
    [[ ! -L "$directory" ]]
    mkdir -p "$directory"
    [[ "$(realpath "$directory")" = "$directory" && -O "$directory" ]]
done
generated="$tree/src/GeneratedBuildInfo.php"
[[ ! -L "$generated" ]]
if [[ ! -e "$generated" ]]; then (set -o noclobber; : > "$generated"); fi
[[ -f "$generated" && -O "$generated" && -n "$(find "$generated" -prune -type f -links 1)" ]]
# Input mtimes alone do not pin the archive metadata under the container
# runtime. Box supports one explicit timestamp for every PHAR entry.
python3 -I -S -B - "$tree/box.json.dist" "$tree/box.json" "${SOURCE_DATE_EPOCH:?SOURCE_DATE_EPOCH is required}" <<'PYCONFIG'
import json,os,re,sys
configuration=json.load(open(sys.argv[1]))
assert re.fullmatch(r'[0-9]{1,12}',sys.argv[3],flags=re.ASCII)
configuration['timestamp']='@'+sys.argv[3]
fd=os.open(sys.argv[2],os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o400)
with os.fdopen(fd,'w') as output:
    json.dump(configuration,output,indent=4)
    output.write('\n')
PYCONFIG
# The existing launcher queries the Git directory. This empty metadata is not
# a source revision and is excluded from the PHAR; build identity stays explicit.
[[ ! -e "$tree/.git" && ! -L "$tree/.git" ]]
git init --quiet "$tree"
metadata="$state/repro-tree"
if [[ -e "$metadata" || -L "$metadata" ]]; then
    [[ -f "$metadata" && ! -L "$metadata" && -O "$metadata" \
        && -n "$(find "$metadata" -prune -type f -links 1 -perm 0400)" ]]
fi
held="$(mktemp "$state/.repro-tree.XXXXXX")"
trap 'rm -f -- "$held"' EXIT
printf '%s\n' "$tree" > "$held"
chmod 0400 "$held"
mv -f -- "$held" "$metadata"
