#!/usr/bin/env bash
# `php` for hosts without PHP: DW_PARITY_PHP=parity/php-docker.sh.
#
# Runs the local php:8.4-cli image as the caller, with the PHP main checkout
# (DW_PARITY_PHP_MAIN), the working directory and /tmp mounted at their own
# paths, and the parity environment forwarded. It uses the host network so
# the harness's loopback mock Servers answer. CI uses the runner's PHP.
set -euo pipefail

: "${DW_PARITY_PHP_MAIN:?Set DW_PARITY_PHP_MAIN to the PHP main checkout}"
env_file="$(mktemp)"
trap 'rm -f "$env_file"' EXIT
env | grep -E '^(DW_|DURABLE_WORKFLOW_|NO_COLOR=|COLUMNS=|LINES=|TERM=|LANG=|LC_[A-Z]+=)' > "$env_file" || true

docker run --rm -i --pull=never --network=host \
  --name "cli-parity-php-$$-$RANDOM" \
  --cpus=1 -m 512m \
  -u "$(id -u):$(id -g)" \
  --env-file "$env_file" \
  -v /tmp:/tmp \
  -v "$DW_PARITY_PHP_MAIN:$DW_PARITY_PHP_MAIN:ro" \
  -v "$PWD:$PWD" -w "$PWD" \
  php:8.4-cli php "$@"
