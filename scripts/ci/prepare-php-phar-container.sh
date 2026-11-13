#!/usr/bin/env bash
# Prepare one inspected runtime for the PHAR caller. Setup owns image ancestry;
# this stage is also runnable against an already-built immutable local image.
set -euo pipefail
umask 077
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
image="${1:?Usage: prepare-php-phar-container.sh CONFIG_ID PRIVATE_STATE}"
state="${2:?Usage: prepare-php-phar-container.sh CONFIG_ID PRIVATE_STATE}"
[[ "$image" =~ ^sha256:[0-9a-f]{64}$ ]]
[[ -d "$state" && ! -L "$state" && -O "$state" && "$(realpath "$state")" = "$state" ]]
case "$state/" in "$root/"*) exit 1 ;; *) ;; esac
case "$root/" in "$state/"*) exit 1 ;; *) ;; esac
for directory in "$root/vendor" "$root/build" "$state/bin" "$state/artifacts" \
    "$state/home" "$state/tmp" "$state/composer-home/cache"; do
    [[ ! -L "$directory" ]]
    mkdir -p "$directory"
    [[ "$(realpath "$directory")" = "$directory" && -O "$directory" ]]
done
generated="$root/src/GeneratedBuildInfo.php"
[[ ! -L "$generated" ]]
if [[ ! -e "$generated" ]]; then (set -o noclobber; : > "$generated"); fi
[[ -f "$generated" && -O "$generated" && -n "$(find "$generated" -prune -type f -links 1)" ]]
bash "$root/scripts/ci/ensure-box.sh" 4.6.6 "$state/artifacts/box.phar"
box="$state/artifacts/box.phar"
[[ -f "$box" && ! -L "$box" && -O "$box" && -n "$(find "$box" -prune -type f -links 1)" ]]
chmod 0400 "$box"
for command in php composer box; do
    ln -sf "$root/scripts/ci/run-php-phar-container.sh" "$state/bin/$command"
done
export CLI_PHP_CI_ROOT="$root" CLI_PHP_CI_IMAGE="$image" CLI_PHP_CI_STATE="$state"
"$state/bin/php" "$root/scripts/ci/probe-php-phar-runtime.php" 8.4
"$state/bin/box" --version
CLI_PHP_CI_TEST_IMAGE="$image" python3 -I -S -B "$root/scripts/ci/test-php-container-tls.py" -v
CLI_PHP_CI_TEST_IMAGE="$image" python3 -I -S -B "$root/scripts/ci/test_php_phar_container.py" -v
