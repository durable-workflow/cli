#!/usr/bin/env bash
# Reuse the inspected base, then prepare only the Rust PHP-reference data.
set -euo pipefail
umask 077
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
image="${1:?Usage: prepare-php-reference-container.sh CONFIG_ID PRIVATE_STATE}"
state="${2:?Usage: prepare-php-reference-container.sh CONFIG_ID PRIVATE_STATE}"
[[ "$image" =~ ^sha256:[0-9a-f]{64}$ ]]
[[ -d "$state" && ! -L "$state" && -O "$state" && "$(realpath "$state")" = "$state" ]]
case "$state/" in "$root/"*) exit 1 ;; *) ;; esac
case "$root/" in "$state/"*) exit 1 ;; *) ;; esac
for directory in "$root/vendor" "$root/rust/target" "$state/bin" "$state/home" \
    "$state/tmp" "$state/composer-home/cache"; do
    [[ ! -L "$directory" ]]
    mkdir -p "$directory"
    [[ "$(realpath "$directory")" = "$directory" && -O "$directory" ]]
done
vectors="$root/rust/target/php-vectors.json"
[[ ! -L "$vectors" ]]
if [[ ! -e "$vectors" ]]; then (set -o noclobber; : > "$vectors"); fi
[[ -f "$vectors" && -O "$vectors" && -n "$(find "$vectors" -prune -type f -links 1)" ]]
for command in php composer; do
    ln -sf "$root/scripts/ci/run-php-reference-container.sh" "$state/bin/$command"
done
export CLI_PHP_CI_ROOT="$root" CLI_PHP_CI_IMAGE="$image" CLI_PHP_CI_STATE="$state"
"$state/bin/php" "$root/scripts/ci/probe-php-reference-runtime.php" 8.4
CLI_PHP_CI_TEST_IMAGE="$image" python3 -I -S -B "$root/scripts/ci/test-php-container-tls.py" -v
CLI_PHP_CI_TEST_IMAGE="$image" python3 -I -S -B "$root/scripts/ci/test_php_reference_container.py" -v
