#!/usr/bin/env bash
set -euo pipefail
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
image="${1:?Usage: prepare-php-repro-container.sh CONFIG_ID PRIVATE_STATE}"
state="${2:?Usage: prepare-php-repro-container.sh CONFIG_ID PRIVATE_STATE}"
[[ "$image" =~ ^sha256:[0-9a-f]{64}$ ]]
[[ -d "$state" && ! -L "$state" && -O "$state" && "$(realpath "$state")" = "$state" ]]
[[ -f "$state/artifacts/box.phar" && ! -L "$state/artifacts/box.phar" ]]
mkdir -p "$state/profile"
[[ ! -L "$state/profile" && "$(realpath "$state/profile")" = "$state/profile" ]]
for command in php composer box; do
    ln -sf "$root/scripts/ci/run-php-phar-container.sh" "$state/profile/$command"
    ln -sf "$root/scripts/ci/run-php-repro-container.sh" "$state/bin/$command"
done
export CLI_PHP_CI_ROOT="$root" CLI_PHP_CI_IMAGE="$image" CLI_PHP_CI_STATE="$state" CLI_PHP_CI_REPRO=1
"$state/bin/php" "$root/scripts/ci/probe-php-phar-runtime.php" 8.4
CLI_PHP_CI_TEST_IMAGE="$image" python3 -I -S -B "$root/scripts/ci/test-php-container-tls.py" -v
CLI_PHP_CI_TEST_IMAGE="$image" python3 -I -S -B "$root/scripts/ci/test_php_repro_container.py" -v
