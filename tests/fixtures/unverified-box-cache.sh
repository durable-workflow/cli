#!/usr/bin/env bash
# Cached-tool branch from scripts/build.sh at 174cd115; synthetic marker only.
set -euo pipefail
TOOLS_DIR="$(dirname "${1:?cached Box path required}")"
BOX_VERSION=4.6.6
BOX_URL="https://github.com/box-project/box/releases/download/${BOX_VERSION}/box.phar"
ensure_tools() {
    mkdir -p "$TOOLS_DIR"
    if [[ ! -x "$TOOLS_DIR/box" ]]; then
        echo ">> Downloading Box ${BOX_VERSION}"
        curl --proto '=https' --proto-redir '=https' -fsSL -o "$TOOLS_DIR/box" "$BOX_URL"
        chmod +x "$TOOLS_DIR/box"
    fi
}
ensure_tools
"$TOOLS_DIR/box" --version
