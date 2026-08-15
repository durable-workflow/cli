#!/usr/bin/env sh
# Durable Workflow CLI installer for Linux and macOS.
#
# Usage:
#   curl -fsSL https://durable-workflow.com/install.sh | sh
#
# Environment variables:
#   VERSION                              Release tag, supported, prerelease, or stable (default: supported).
#   DURABLE_WORKFLOW_INSTALL_DIR         Install directory (default: ~/.local/bin).
#   DURABLE_WORKFLOW_BIN_NAME            Executable name (default: dw).
#   DURABLE_WORKFLOW_RELEASE_BASE_URL    Release base URL override for tests.
#   DURABLE_WORKFLOW_QUALIFIED_AUTHORITY_URL
#                                        Qualified artifact authority override for tests.
#   DURABLE_WORKFLOW_INSTALL_VERIFY_ATTESTATIONS
#                                        Set to 1 to verify GitHub artifact attestations with gh.

set -eu

REPO="durable-workflow/cli"
BIN_NAME="${DURABLE_WORKFLOW_BIN_NAME:-dw}"
INSTALL_DIR="${DURABLE_WORKFLOW_INSTALL_DIR:-$HOME/.local/bin}"
VERSION="${VERSION:-supported}"
RELEASE_BASE_URL="${DURABLE_WORKFLOW_RELEASE_BASE_URL:-https://github.com/${REPO}/releases}"
RELEASE_BASE_URL="${RELEASE_BASE_URL%/}"
QUALIFIED_AUTHORITY_URL="${DURABLE_WORKFLOW_QUALIFIED_AUTHORITY_URL:-https://durable-workflow.com/public-artifact-compatibility-evidence.json}"
VERIFY_ATTESTATIONS="${DURABLE_WORKFLOW_INSTALL_VERIFY_ATTESTATIONS:-0}"

err() { printf '\033[31merror:\033[0m %s\n' "$*" >&2; exit 1; }
info() { printf '\033[32m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[33mwarning:\033[0m %s\n' "$*" >&2; }

uname_s=$(uname -s)
uname_m=$(uname -m)

case "$uname_s" in
    Linux) os="linux" ;;
    Darwin) os="macos" ;;
    *) err "unsupported OS: $uname_s (Windows users: use install.ps1)" ;;
esac

case "$uname_m" in
    x86_64|amd64) arch="x86_64" ;;
    arm64|aarch64) arch="aarch64" ;;
    *) err "unsupported architecture: $uname_m" ;;
esac

if [ "$os" = "macos" ] && [ "$arch" = "x86_64" ]; then
    err "macos-x86_64 binaries are not currently published. Use the PHAR with a system PHP instead."
fi

asset="dw-${os}-${arch}"
command -v curl >/dev/null 2>&1 || err "curl is required"

if [ "$VERSION" = "supported" ] || [ "$VERSION" = "prerelease" ]; then
    requested_channel="$VERSION"
    info "Resolving the qualified CLI release"
    if ! VERSION=$(curl -fsSL --retry 3 "$QUALIFIED_AUTHORITY_URL" | tr '{},' '\n\n\n' | awk '
        /"schema"[[:space:]]*:[[:space:]]*"durable-workflow\.docs\.public-artifact-compatibility-evidence"/ && !schema_seen {
            schema_seen=1
        }
        /"schema_version"[[:space:]]*:[[:space:]]*2([[:space:]]|$)/ && !schema_version_seen {
            schema_version_seen=1
        }
        /"outcome"[[:space:]]*:/ && !outcome_seen {
            outcome_seen=1
            if ($0 ~ /"outcome"[[:space:]]*:[[:space:]]*"pass"/) outcome_pass=1
        }
        /"qualified_artifact_versions"[[:space:]]*:/ {
            qualified_versions=1
            next
        }
        qualified_versions && /"cli"[[:space:]]*:/ {
            version=$0
            sub(/^.*"cli"[[:space:]]*:[[:space:]]*"v?/, "", version)
            sub(/".*$/, "", version)
            qualified_versions=0
        }
        END {
            if (schema_seen && schema_version_seen && outcome_pass && version ~ /^[0-9]+\.[0-9]+\.[0-9]+(-(alpha|beta|rc)\.[0-9]+)?$/) {
                print version
                exit 0
            }
            exit 1
        }
    '); then
        err "could not resolve a passing qualified CLI release from $QUALIFIED_AUTHORITY_URL"
    fi
    if [ "$requested_channel" = "prerelease" ]; then
        printf '%s\n' "$VERSION" \
            | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+-(alpha|beta|rc)\.[0-9]+$' \
            || err "qualified CLI release is not an alpha, beta, or rc version"
    fi
fi

if [ "$VERSION" = "latest" ] || [ "$VERSION" = "stable" ]; then
    url="${RELEASE_BASE_URL}/latest/download/${asset}"
    checksum_url="${RELEASE_BASE_URL}/latest/download/SHA256SUMS"
else
    release_version="${VERSION#v}"
    url="${RELEASE_BASE_URL}/download/${release_version}/${asset}"
    checksum_url="${RELEASE_BASE_URL}/download/${release_version}/SHA256SUMS"
fi
sha256_file() {
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum "$1" | awk '{print $1}'
        return 0
    fi

    if command -v shasum >/dev/null 2>&1; then
        shasum -a 256 "$1" | awk '{print $1}'
        return 0
    fi

    return 127
}

mkdir -p "$INSTALL_DIR"
tmpdir=$(mktemp -d)
tmp="$tmpdir/$asset"
sums="$tmpdir/SHA256SUMS"
trap 'rm -rf "$tmpdir"' EXIT

info "Downloading $asset"
if ! curl -fsSL --retry 3 -o "$tmp" "$url"; then
    err "download failed: $url"
fi

[ -s "$tmp" ] || err "downloaded file is empty"

info "Verifying checksum"
if ! curl -fsSL --retry 3 -o "$sums" "$checksum_url"; then
    err "checksum download failed: $checksum_url"
fi

if ! expected_sha=$(awk -v asset="$asset" '$2 == asset || $2 == "*" asset {print $1; found=1} END {if (!found) exit 1}' "$sums"); then
    err "checksum for $asset not found in SHA256SUMS"
fi

if ! actual_sha=$(sha256_file "$tmp"); then
    err "sha256sum or shasum is required to verify the download"
fi

actual_sha=$(printf '%s' "$actual_sha" | tr '[:upper:]' '[:lower:]')
expected_sha=$(printf '%s' "$expected_sha" | tr '[:upper:]' '[:lower:]')
if [ "$actual_sha" != "$expected_sha" ]; then
    err "checksum verification failed for $asset"
fi

if [ "$VERIFY_ATTESTATIONS" = "1" ]; then
    command -v gh >/dev/null 2>&1 || err "gh is required when DURABLE_WORKFLOW_INSTALL_VERIFY_ATTESTATIONS=1"

    info "Verifying GitHub artifact attestations"
    gh attestation verify "$tmp" --repo "$REPO"
    gh attestation verify "$sums" --repo "$REPO"
fi

chmod +x "$tmp"
mv "$tmp" "$INSTALL_DIR/$BIN_NAME"
rm -rf "$tmpdir"
trap - EXIT

info "Installed $BIN_NAME to $INSTALL_DIR"

case ":$PATH:" in
    *":$INSTALL_DIR:"*) ;;
    *)
        warn "$INSTALL_DIR is not in your PATH. Add this to your shell profile:"
        printf '    export PATH="%s:$PATH"\n' "$INSTALL_DIR"
        ;;
esac

"$INSTALL_DIR/$BIN_NAME" --version || true
