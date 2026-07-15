#!/usr/bin/env bash
# Build the durable-workflow PHAR twice from the current source tree and
# confirm both builds produce byte-identical output. This is the local
# proof point for the reproducible-build claim documented in
# docs/distribution.md: the same source must produce the same artifact,
# so any operator can independently verify the bytes a tagged release
# published.
#
# Usage:
#   scripts/verify-reproducible-build.sh
#
# Honors SOURCE_DATE_EPOCH; falls back to the current HEAD commit time
# so a clean checkout reproduces without extra arguments.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

BUILD_DIR="$ROOT/build"
PHAR_OUT="$BUILD_DIR/dw.phar"
ATTEMPT_DIR="$(mktemp -d "${TMPDIR:-/tmp}/dw-cli-repro.XXXXXX")"

cleanup() {
    rm -rf -- "$ATTEMPT_DIR"
}

trap cleanup EXIT
trap 'exit 1' HUP INT TERM

err() {
    printf 'error: %s\n' "$*" >&2
    exit 1
}

sha256_of() {
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum "$1" | awk '{print $1}'
    elif command -v shasum >/dev/null 2>&1; then
        shasum -a 256 "$1" | awk '{print $1}'
    else
        err "sha256sum or shasum is required"
    fi
}

if [[ -z "${SOURCE_DATE_EPOCH:-}" ]]; then
    if [[ -d "$ROOT/.git" ]] && command -v git >/dev/null 2>&1; then
        SOURCE_DATE_EPOCH="$(git -C "$ROOT" log -1 --pretty=%ct 2>/dev/null || true)"
    fi
    if [[ -z "${SOURCE_DATE_EPOCH:-}" ]]; then
        err "SOURCE_DATE_EPOCH is unset and git HEAD timestamp is not available"
    fi
    export SOURCE_DATE_EPOCH
fi

echo ">> Reproducible-build check: SOURCE_DATE_EPOCH=$SOURCE_DATE_EPOCH"

scripts/build.sh clean >/dev/null
scripts/build.sh phar
[[ -s "$PHAR_OUT" ]] || err "first build did not produce $PHAR_OUT"
first="$ATTEMPT_DIR/dw.phar.1"
cp "$PHAR_OUT" "$first"
sha1="$(sha256_of "$first")"
echo ">> First build sha256:  $sha1"

scripts/build.sh clean >/dev/null
scripts/build.sh phar
[[ -s "$PHAR_OUT" ]] || err "second build did not produce $PHAR_OUT"
second="$ATTEMPT_DIR/dw.phar.2"
cp "$PHAR_OUT" "$second"
sha2="$(sha256_of "$second")"
echo ">> Second build sha256: $sha2"

if [[ "$sha1" != "$sha2" ]]; then
    if command -v cmp >/dev/null 2>&1; then
        cmp -l "$first" "$second" | head -20 >&2 || true
    fi
    err "PHAR builds are not bit-identical (sha256 differs). See docs/distribution.md for the reproducible-build contract."
fi

printf '\nReproducible build verified: dw.phar sha256=%s\n' "$sha1"
