#!/usr/bin/env bash
set -euo pipefail
command="${0##*/}"
case "$command" in php|composer|box) ;; *) echo 'Invoke this reproducibility launcher as php, composer or box.' >&2; exit 2 ;; esac
source_root="${CLI_PHP_CI_ROOT:?PHP CI source root is required}"
state="${CLI_PHP_CI_STATE:?PHP CI state is required}"
[[ "${CLI_PHP_CI_REPRO:-}" = 1 ]]
root="$source_root"
metadata="$state/repro-tree"
if [[ -e "$metadata" || -L "$metadata" ]]; then
    [[ -f "$metadata" && ! -L "$metadata" && -O "$metadata" \
        && -n "$(find "$metadata" -prune -type f -links 1 -perm 0400)" ]] || exit 1
    mapfile -t fields < "$metadata"
    [[ "${#fields[@]}" = 1 ]]
    tree="${fields[0]}"
    case "$tree" in "${RUNNER_TEMP:?RUNNER_TEMP is required}"/dw-cli-repro.*/source.[12]) ;; *) exit 1 ;; esac
    case "$PWD/" in "$tree/"*)
        [[ -d "$tree" && ! -L "$tree" && -O "$tree" && "$(realpath "$tree")" = "$tree" ]]
        case "$tree/" in "$state/"*|"$source_root/"*) exit 1 ;; *) ;; esac
        root="$tree" ;;
    *) ;;
    esac
fi
# The helper's archive cache is writable build data. Execute only the pinned
# read-only Box artifact, including when the original build script invokes PHP.
arguments=("$@")
for argument in "$@"; do
    if [[ "$argument" = "$root/build/.tools/box" ]]; then
        [[ "$command" = php && "$#" -ge 3 && "$1" = -d && "$2" = phar.readonly=0 && "$3" = "$argument" ]] || exit 1
        arguments=(-d phar.readonly=0 "$root/scripts/ci/exec-box.php" "$state/artifacts/box.phar" "${@:4}")
        break
    fi
done
classic="$state/profile/$command"
[[ -L "$classic" && "$(realpath "$classic")" = "$source_root/scripts/ci/run-php-phar-container.sh" ]]
CLI_PHP_CI_ROOT="$root" exec "$classic" "${arguments[@]}"
