#!/usr/bin/env bash
# Box 4.6.6: release asset 233947190, detached signature 233947189.
# Signature fingerprint: 41539BBD4020945DB378F98B2DF45277AEF09A2F.
set -euo pipefail
umask 077
export LC_ALL=C

version="${1:?Usage: ensure-box.sh VERSION ABSOLUTE_DESTINATION}"
destination="${2:?Usage: ensure-box.sh VERSION ABSOLUTE_DESTINATION}"
expected_sha=aa0966319f709e74bf2bf1d58ddb987903ae4f6d0a9d335ec2261813c189f7fc
expected_size=2947031

refuse() {
    echo "Box acquisition refused: $1" >&2
    exit 1
}

[[ "$version" = 4.6.6 ]] || refuse 'version has no reviewed artifact identity'
[[ "$destination" = /* ]] || refuse 'destination must be absolute'
[[ ! -L "$destination" ]] || refuse 'cached artifact must not be a symlink'
directory="$(dirname "$destination")"
mkdir -p "$directory"
[[ "$(cd "$directory" && pwd -P)" = "$directory" ]] || refuse 'cache directory must be canonical'

verify() {
    local file="$1" actual
    [[ -f "$file" && ! -L "$file" ]] || refuse 'artifact must be a regular file'
    [[ "$(wc -c < "$file")" -eq "$expected_size" ]] || refuse 'artifact size differs from the reviewed asset'
    if command -v sha256sum >/dev/null 2>&1; then
        actual="$(sha256sum "$file" | awk '{print $1}')"
    else
        actual="$(shasum -a 256 "$file" | awk '{print $1}')"
    fi
    [[ "$actual" = "$expected_sha" ]] || refuse 'artifact digest differs from the reviewed asset'
}

if [[ -e "$destination" ]]; then
    verify "$destination"
    chmod 0755 "$destination"
    exit 0
fi

held="$(mktemp -d "$directory/.box-download.XXXXXX")"
trap 'rm -rf -- "$held"' EXIT
trap 'exit 1' HUP INT TERM
# Also bound header-file growth; this child-only ceiling exceeds the asset.
ulimit -f 8192
url=https://github.com/box-project/box/releases/download/4.6.6/box.phar
for attempt in 1 2 3 4; do
    # Check every destination before contacting it. Never use curl -L here.
    [[ ! "$url" =~ [[:space:][:cntrl:]] && "$url" != *'#'* ]] || refuse 'invalid redirect URL'
    case "$url" in
        https://github.com/box-project/box/releases/download/4.6.6/box.phar|\
        https://release-assets.githubusercontent.com/*|https://objects.githubusercontent.com/*) ;;
        *) refuse 'redirect host or scheme is outside the reviewed set' ;;
    esac
    status="$(curl --proto '=https' --tlsv1.2 --silent --show-error --fail \
        --max-time 45 --connect-timeout 10 --max-filesize "$expected_size" \
        --dump-header "$held/headers" --output "$held/artifact" \
        --write-out '%{http_code}' "$url")" || refuse 'download failed'
    case "$status" in
        200)
            verify "$held/artifact"
            chmod 0755 "$held/artifact"
            mv -f -- "$held/artifact" "$destination"
            printf 'Box %s verified: sha256=%s bytes=%s\n' "$version" "$expected_sha" "$expected_size"
            exit 0
            ;;
        301|302|303|307|308)
            url="$(awk '
                tolower($0) ~ /^location:/ {
                    sub(/^[^:]*:[ \t]*/, ""); sub(/\r$/, ""); value=$0; count++
                }
                END { if (count != 1) exit 1; print value }
            ' "$held/headers")" || refuse 'redirect requires one Location header'
            ;;
        *) refuse 'unexpected HTTP status' ;;
    esac
done
refuse 'redirect limit exceeded'
