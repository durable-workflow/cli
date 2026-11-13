#!/usr/bin/env bash
set -euo pipefail
umask 077

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
minor="${1:?Usage: setup-php-container.sh 8.2|8.4|8.5}"
image="$(python3 -I -S -B - "$root/scripts/ci/php-container-images.json" "$minor" <<'PY'
import json,re,sys
images=json.load(open(sys.argv[1]))
image=images[sys.argv[2]]
assert re.fullmatch(r'php:8\.[245]\.\d+-cli-bookworm@sha256:[0-9a-f]{64}', image)
print(image)
PY
)"
[[ -f "$root/.central-apt-source/docker/apt/require-fixed-bookworm-apt.sh" ]]
[[ "$(git -C "$root/.central-apt-source" rev-parse HEAD)" = 18783be6bef737cc778e4c7282a8797e39daa61a ]]
echo '2a36a2417e8947357ea6f6cbc2f6c53a834f3c069f8013a34d6d861b7123d576  '"$root/.central-apt-source/docker/apt/require-fixed-bookworm-apt.sh" | sha256sum --check --strict

runtime="cli-php-ci:${minor}-${GITHUB_RUN_ID:-local}-${GITHUB_RUN_ATTEMPT:-1}"
base="$runtime-base"
docker build --file "$root/docker/php-ci/Dockerfile" --build-arg "PHP_IMAGE=$image" --tag "$base" "$root"
base_id="$(docker image inspect "$base" --format '{{.Id}}')"
docker build --file "$root/docker/php-ci/cli.Dockerfile" --build-arg "PHP_RUNTIME_IMAGE=$base" --tag "$runtime" "$root"
[[ "$(docker image inspect "$base" --format '{{.Id}}')" = "$base_id" ]]
runtime_id="$(docker image inspect "$runtime" --format '{{.Id}}')"
docker image inspect "$base_id" "$runtime_id" | python3 -I -S -B -c '
import json,sys
base,runtime=json.load(sys.stdin)
layers=base["RootFS"]["Layers"]
assert runtime["RootFS"]["Layers"][:len(layers)] == layers, "CLI overlay must extend the inspected shared base"
print("php_ci_base="+base["Id"])
'
docker run --rm --init --name "cli-php-ci-probe-${minor}-${GITHUB_RUN_ID:-local}" \
    --cpus 1 --memory 512m --pids-limit 64 --network none \
    --user "$(id -u):$(id -g)" --cap-drop ALL --security-opt no-new-privileges \
    --mount "type=bind,source=$root/scripts/ci/probe-php-test-runtime.php,target=/probe.php,readonly" \
    "$runtime_id" php /probe.php "$minor"
printf 'php_ci_image=%s\n' "$runtime_id"

state="$(mktemp -d "${RUNNER_TEMP:?RUNNER_TEMP is required}/cli-php-ci.XXXXXX")"
mkdir -p "$state/bin" "$state/home" "$state/tmp" "$state/composer-home/cache"
ln -sf "$root/scripts/ci/run-php-container.sh" "$state/bin/php"
ln -sf "$root/scripts/ci/run-php-container.sh" "$state/bin/composer"
CLI_PHP_CI_TEST_IMAGE="$runtime_id" CLI_PHP_CI_ROOT="$root" CLI_PHP_CI_STATE="$state" \
    python3 -I -S -B "$root/scripts/ci/test-php-container-tls.py" -v
{
    echo "CLI_PHP_CI_IMAGE=$runtime_id"
    echo "CLI_PHP_CI_ROOT=$root"
    echo "CLI_PHP_CI_STATE=$state"
    echo "COMPOSER_HOME=$state/composer-home"
    echo "COMPOSER_CACHE_DIR=$state/composer-home/cache"
    echo "TMPDIR=$state/tmp"
} >> "${GITHUB_ENV:?GITHUB_ENV is required}"
echo "$state/bin" >> "${GITHUB_PATH:?GITHUB_PATH is required}"
