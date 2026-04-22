#!/usr/bin/env sh
# Verify Durable Workflow CLI release assets from a downloaded release directory.

set -eu

REPO="${DURABLE_WORKFLOW_REPO:-durable-workflow/cli}"
RELEASE_DIR="."
VERIFY_ATTESTATIONS="${DURABLE_WORKFLOW_VERIFY_ATTESTATIONS:-0}"

usage() {
    cat <<'EOF'
Usage: scripts/verify-release.sh [--attest] [--repo owner/name] [release-dir]

Verifies every local asset listed in SHA256SUMS. Pass --attest to also verify
GitHub artifact attestations for each checked asset when gh is installed.
EOF
}

err() {
    printf 'error: %s\n' "$*" >&2
    exit 1
}

while [ "$#" -gt 0 ]; do
    case "$1" in
        --attest)
            VERIFY_ATTESTATIONS=1
            shift
            ;;
        --repo)
            [ "$#" -ge 2 ] || err "--repo requires owner/name"
            REPO="$2"
            shift 2
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        --*)
            err "unknown option: $1"
            ;;
        *)
            RELEASE_DIR="$1"
            shift
            ;;
    esac
done

[ -d "$RELEASE_DIR" ] || err "release directory does not exist: $RELEASE_DIR"
[ -s "$RELEASE_DIR/SHA256SUMS" ] || err "missing SHA256SUMS in $RELEASE_DIR"

if command -v sha256sum >/dev/null 2>&1; then
    (
        cd "$RELEASE_DIR"
        sha256sum -c SHA256SUMS --ignore-missing
    )
elif command -v shasum >/dev/null 2>&1; then
    (
        cd "$RELEASE_DIR"
        while read -r expected asset; do
            asset=${asset#\*}
            [ -n "$asset" ] || continue
            [ -e "$asset" ] || continue

            actual=$(shasum -a 256 "$asset" | awk '{print $1}')
            if [ "$actual" != "$expected" ]; then
                printf '%s: FAILED\n' "$asset" >&2
                exit 1
            fi

            printf '%s: OK\n' "$asset"
        done < SHA256SUMS
    )
else
    err "sha256sum or shasum is required"
fi

if [ "$VERIFY_ATTESTATIONS" = "1" ]; then
    command -v gh >/dev/null 2>&1 || err "gh is required for attestation verification"
    awk '{print $2}' "$RELEASE_DIR/SHA256SUMS" | sed 's/^\*//' | while IFS= read -r asset; do
        [ -n "$asset" ] || continue
        [ "$asset" = "SHA256SUMS" ] && continue
        [ -e "$RELEASE_DIR/$asset" ] || continue
        gh attestation verify "$RELEASE_DIR/$asset" --repo "$REPO"
    done

    gh attestation verify "$RELEASE_DIR/SHA256SUMS" --repo "$REPO"
fi

printf 'Release assets verified in %s\n' "$RELEASE_DIR"
