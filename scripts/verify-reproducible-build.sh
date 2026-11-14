#!/usr/bin/env bash
# Build the durable-workflow PHAR twice from independent copies of the
# committed source tree and confirm both builds produce byte-identical
# output. This is the local
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

ATTEMPT_DIR="$(mktemp -d "${TMPDIR:-/tmp}/dw-cli-repro.XXXXXX")"
FIRST_TREE="$ATTEMPT_DIR/source.1"
SECOND_TREE="$ATTEMPT_DIR/source.2"

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

if ! command -v git >/dev/null 2>&1; then
    err "git is required to create independent source trees"
fi
if ! command -v tar >/dev/null 2>&1; then
    err "tar is required to create independent source trees"
fi

SOURCE_COMMIT="$(git -C "$ROOT" rev-parse --verify 'HEAD^{commit}' 2>/dev/null || true)"
if [[ -z "$SOURCE_COMMIT" ]]; then
    err "git HEAD is not available"
fi

if [[ -z "${SOURCE_DATE_EPOCH:-}" ]]; then
    SOURCE_DATE_EPOCH="$(git -C "$ROOT" log -1 --pretty=%ct "$SOURCE_COMMIT" 2>/dev/null || true)"
    if [[ -z "${SOURCE_DATE_EPOCH:-}" ]]; then
        err "SOURCE_DATE_EPOCH is unset and git HEAD timestamp is not available"
    fi
    export SOURCE_DATE_EPOCH
fi

if [[ -z "${DW_CLI_COMMIT:-}" ]]; then
    export DW_CLI_COMMIT="$SOURCE_COMMIT"
fi

if [[ -z "${DW_CLI_VERSION:-}" && "${GITHUB_REF_TYPE:-}" != "tag" ]]; then
    source_tag="$(git -C "$ROOT" describe --tags --exact-match "$SOURCE_COMMIT" 2>/dev/null || true)"
    if [[ -n "$source_tag" ]]; then
        export DW_CLI_VERSION="${source_tag#v}"
    fi
fi

echo ">> Reproducible-build check: SOURCE_DATE_EPOCH=$SOURCE_DATE_EPOCH"

create_source_tree() {
    local tree="$1"

    mkdir -p "$tree"
    git -C "$ROOT" archive --format=tar "$SOURCE_COMMIT" | tar -xf - -C "$tree"
}

build_attempt() {
    local label="$1"
    local tree="$2"
    local output="$3"

    echo ">> Preparing $label build in an independent source tree"
    create_source_tree "$tree"
    (
        cd "$tree"
        scripts/build.sh phar
    )
    [[ -s "$tree/build/dw.phar" ]] || err "$label build did not produce $tree/build/dw.phar"
    cp "$tree/build/dw.phar" "$output"
}

first="$ATTEMPT_DIR/dw.phar.1"
build_attempt "first" "$FIRST_TREE" "$first"
sha1="$(sha256_of "$first")"
echo ">> First build sha256:  $sha1"

second="$ATTEMPT_DIR/dw.phar.2"
build_attempt "second" "$SECOND_TREE" "$second"
sha2="$(sha256_of "$second")"
echo ">> Second build sha256: $sha2"

if [[ "$sha1" != "$sha2" ]]; then
    if command -v cmp >/dev/null 2>&1; then
        cmp -l "$first" "$second" | head -20 >&2 || true
    fi
    err "PHAR builds are not bit-identical (sha256 differs). See docs/distribution.md for the reproducible-build contract."
fi

printf '\nReproducible build verified: dw.phar sha256=%s\n' "$sha1"
