#!/usr/bin/env bash
# Verify that CLI release assets are downloadable from the public GitHub
# releases/download endpoints used by installers and downstream builds.

set -euo pipefail

REPO="${DURABLE_WORKFLOW_REPO:-durable-workflow/cli}"
ATTEMPTS="${DURABLE_WORKFLOW_RELEASE_ASSET_ATTEMPTS:-12}"
SLEEP_SECONDS="${DURABLE_WORKFLOW_RELEASE_ASSET_RETRY_SLEEP:-10}"

err() {
    printf 'error: %s\n' "$*" >&2
    exit 1
}

usage() {
    cat >&2 <<'USAGE'
Usage: scripts/verify-public-release-assets.sh <tag> [asset ...]

Verifies that every named asset is downloadable from:
  https://github.com/<repo>/releases/download/<tag>/<asset>

Tags may include one optional leading "v"; public release URLs use the
repository's bare tag name.
If no assets are provided, the required Unix installer surface is checked.
Override the repository with DURABLE_WORKFLOW_REPO=owner/name.
USAGE
}

raw_tag="${1:-}"
if [ -z "$raw_tag" ]; then
    usage
    exit 2
fi
shift

tag="${raw_tag#v}"
if ! printf '%s\n' "$tag" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+([-.][0-9A-Za-z][0-9A-Za-z.-]*)?$'; then
    err "invalid release tag: $raw_tag"
fi

if [ "$#" -eq 0 ]; then
    set -- \
        dw.phar \
        dw-linux-x86_64 \
        dw-linux-aarch64 \
        dw-macos-aarch64 \
        dw.rb \
        install.sh \
        install.ps1 \
        verify-release.sh \
        SHA256SUMS
fi

case "$ATTEMPTS" in
    ''|*[!0-9]*) err "DURABLE_WORKFLOW_RELEASE_ASSET_ATTEMPTS must be a positive integer" ;;
esac

case "$SLEEP_SECONDS" in
    ''|*[!0-9]*) err "DURABLE_WORKFLOW_RELEASE_ASSET_RETRY_SLEEP must be a non-negative integer" ;;
esac

if [ "$ATTEMPTS" -lt 1 ]; then
    err "DURABLE_WORKFLOW_RELEASE_ASSET_ATTEMPTS must be at least 1"
fi

command -v curl >/dev/null 2>&1 || err "curl is required"

for artifact in "$@"; do
    [ -n "$artifact" ] || err "asset names must not be empty"

    url="https://github.com/${REPO}/releases/download/${tag}/${artifact}"
    ok=0
    attempt=1

    while [ "$attempt" -le "$ATTEMPTS" ]; do
        if curl -fsSLI --retry 3 --retry-all-errors --connect-timeout 10 "$url" >/dev/null; then
            ok=1
            break
        fi

        if [ "$attempt" -lt "$ATTEMPTS" ]; then
            printf 'Waiting for public release asset (%s/%s): %s\n' "$attempt" "$ATTEMPTS" "$artifact" >&2
            sleep "$SLEEP_SECONDS"
        fi

        attempt=$((attempt + 1))
    done

    if [ "$ok" -ne 1 ]; then
        err "public release asset is not downloadable: $url"
    fi

    printf '%s: OK\n' "$artifact"
done
