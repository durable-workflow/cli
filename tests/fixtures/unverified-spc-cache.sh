#!/usr/bin/env bash
# SPC cached-tool branch from scripts/build.sh at b962dca9; marker only.
set -euo pipefail
TOOLS_DIR="$(dirname "${1:?cached SPC path required}")"
ensure_spc() {
    if [[ ! -x "$TOOLS_DIR/spc" ]]; then
        curl --proto '=https' --proto-redir '=https' -fsSL -o "$TOOLS_DIR/spc" \
            https://dl.static-php.dev/static-php-cli/spc-bin/nightly/spc-linux-x86_64
        chmod +x "$TOOLS_DIR/spc"
    fi
}
ensure_spc
"$TOOLS_DIR/spc" --version
