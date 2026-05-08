#!/usr/bin/env bash
# Build the durable-workflow CLI as a PHAR and optionally as a standalone
# static binary (PHAR embedded in phpmicro).
#
# Usage:
#   scripts/build.sh phar        # Build the PHAR only (requires system PHP)
#   scripts/build.sh binary      # Build the PHAR + standalone native binary
#   scripts/build.sh clean       # Remove build artifacts
#
# Outputs land in ./build/.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

BUILD_DIR="$ROOT/build"
TOOLS_DIR="$ROOT/build/.tools"
PHAR_OUT="$BUILD_DIR/dw.phar"

# Extensions required by the CLI at runtime. Keep this list in sync with the
# CI matrix in .github/workflows/release.yml.
SPC_EXTENSIONS="curl,mbstring,openssl,phar,tokenizer,ctype,filter,fileinfo,iconv,sockets"

BOX_VERSION="${BOX_VERSION:-4.6.6}"
BOX_URL="https://github.com/box-project/box/releases/download/${BOX_VERSION}/box.phar"

detect_platform() {
    local os arch
    case "$(uname -s)" in
        Linux)   os="linux" ;;
        Darwin)  os="macos" ;;
        *) echo "Unsupported OS: $(uname -s)" >&2; exit 1 ;;
    esac
    case "$(uname -m)" in
        x86_64|amd64) arch="x86_64" ;;
        arm64|aarch64) arch="aarch64" ;;
        *) echo "Unsupported arch: $(uname -m)" >&2; exit 1 ;;
    esac
    echo "${os}-${arch}"
}

ensure_tools() {
    mkdir -p "$TOOLS_DIR"
    if [[ ! -x "$TOOLS_DIR/box" ]]; then
        echo ">> Downloading Box ${BOX_VERSION}"
        curl -fsSL -o "$TOOLS_DIR/box" "$BOX_URL"
        chmod +x "$TOOLS_DIR/box"
    fi
}

ensure_spc() {
    local platform
    platform="$(detect_platform)"
    if [[ ! -x "$TOOLS_DIR/spc" ]]; then
        echo ">> Downloading spc (static-php-cli) for ${platform}"
        curl -fsSL -o "$TOOLS_DIR/spc" \
            "https://dl.static-php.dev/static-php-cli/spc-bin/nightly/spc-${platform}"
        chmod +x "$TOOLS_DIR/spc"
    fi
}

install_composer_deps() {
    echo ">> Installing production Composer dependencies"
    composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
}

ensure_source_date_epoch() {
    # Reproducible builds (https://reproducible-builds.org/specs/source-date-epoch/):
    # default SOURCE_DATE_EPOCH to the current commit timestamp so the same
    # source tree always produces the same PHAR bytes. Callers can override.
    if [[ -z "${SOURCE_DATE_EPOCH:-}" ]]; then
        if [[ -d "$ROOT/.git" ]] && command -v git >/dev/null 2>&1; then
            local commit_time
            commit_time="$(git -C "$ROOT" log -1 --pretty=%ct 2>/dev/null || true)"
            if [[ "$commit_time" =~ ^[0-9]+$ ]]; then
                export SOURCE_DATE_EPOCH="$commit_time"
            fi
        fi
    fi
    if [[ -n "${SOURCE_DATE_EPOCH:-}" ]]; then
        echo ">> SOURCE_DATE_EPOCH=$SOURCE_DATE_EPOCH"
    fi
}

normalize_mtimes() {
    # Pin mtimes on every input that ends up inside the PHAR so Box's
    # archive entries hash identically across builds. Box also reads
    # SOURCE_DATE_EPOCH directly, but normalizing the filesystem first
    # protects us from compactor differences and ext-phar timestamps.
    [[ -n "${SOURCE_DATE_EPOCH:-}" ]] || return 0
    if ! command -v find >/dev/null 2>&1; then
        return 0
    fi
    local epoch="$SOURCE_DATE_EPOCH"
    local target
    for target in src schemas bin vendor; do
        [[ -e "$ROOT/$target" ]] || continue
        find "$ROOT/$target" -exec touch -h -d "@$epoch" {} + 2>/dev/null || true
    done
    [[ -e "$ROOT/src/GeneratedBuildInfo.php" ]] && \
        touch -h -d "@$epoch" "$ROOT/src/GeneratedBuildInfo.php" 2>/dev/null || true
}

build_phar() {
    ensure_tools
    ensure_source_date_epoch
    php scripts/generate-build-info.php
    install_composer_deps
    mkdir -p "$BUILD_DIR"
    normalize_mtimes
    echo ">> Building PHAR via Box"
    php -d phar.readonly=0 "$TOOLS_DIR/box" compile --no-parallel
    echo ">> PHAR built: $PHAR_OUT"
}

build_binary() {
    build_phar
    ensure_spc
    local php_version="${PHP_VERSION:-8.4}"
    local platform
    platform="$(detect_platform)"
    local out_name="dw-${platform}"

    pushd "$TOOLS_DIR" >/dev/null
    echo ">> Downloading PHP ${php_version} source + extension deps"
    ./spc download --with-php="$php_version" --for-extensions="$SPC_EXTENSIONS" --prefer-pre-built
    echo ">> Compiling phpmicro with required extensions"
    ./spc build "$SPC_EXTENSIONS" --build-micro
    echo ">> Embedding PHAR into micro SAPI"
    ./spc micro:combine "$PHAR_OUT" --output="$BUILD_DIR/$out_name"
    popd >/dev/null

    chmod +x "$BUILD_DIR/$out_name"
    echo ">> Standalone binary: $BUILD_DIR/$out_name"
}

clean() {
    rm -rf "$BUILD_DIR"
    rm -f "$ROOT/src/GeneratedBuildInfo.php"
    echo ">> Cleaned $BUILD_DIR"
}

cmd="${1:-phar}"
case "$cmd" in
    phar)   build_phar ;;
    binary) build_binary ;;
    clean)  clean ;;
    *) echo "Usage: $0 {phar|binary|clean}" >&2; exit 1 ;;
esac
