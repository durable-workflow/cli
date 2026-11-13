#!/usr/bin/env bash
# Acquire pinned SPC data or run the complete Linux pipeline through one holder.
set -euo pipefail
umask 077
export LC_ALL=C
unset TAR_OPTIONS GZIP GZIP_OPT

platform="${1:?Usage: ensure-spc.sh PLATFORM ABSOLUTE_CACHE [acquire|check|run ARGS...]}"
destination="${2:?Usage: ensure-spc.sh PLATFORM ABSOLUTE_CACHE [acquire|check|run ARGS...]}"
role="${3:-acquire}"
command -v python3 >/dev/null 2>&1 || { echo 'SPC requires Python 3 for descriptor-bound execution.' >&2; exit 1; }
source_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
# Capture and check exactly the code passed to isolated Python, with no reopen.
spc_code="$(cat "$source_dir/spc-artifact.py")"$'\n'
expected_code_sha=2bc2245ae63dfac589aecb609cc3e260b442d50fe5e1e0f5f59181eb12d3c28d
sha256_of() {
    if command -v sha256sum >/dev/null 2>&1; then sha256sum | awk '{print $1}'; else shasum -a 256 | awk '{print $1}'; fi
}
[[ "$(printf '%s' "$spc_code" | sha256_of)" = "$expected_code_sha" ]] || { echo 'SPC controller source differs.' >&2; exit 1; }
spc_python() { python3 -I -S -B -c "$spc_code" "$@"; }
read -r version asset_id archive_sha archive_size expected_sha expected_size release_url < <(spc_python metadata "$platform")

refuse() { echo "SPC acquisition refused: $1" >&2; exit 1; }
case "$role" in
    check) spc_python check "$platform"; exit 0 ;;
    acquire) [[ $# -le 3 ]] || refuse 'unexpected acquisition arguments' ;;
    run) spc_python check "$platform" ;;
    *) refuse 'unknown operation' ;;
esac
[[ "$destination" = /* ]] || refuse 'destination must be absolute'
[[ ! -L "$destination" ]] || refuse 'cached artifact must not be a symlink'
directory="$(dirname "$destination")"
mkdir -p "$directory"
[[ "$(cd "$directory" && pwd -P)" = "$directory" ]] || refuse 'cache directory must be canonical'

verify() {
    local file="$1" digest="$2" size="$3"
    [[ -f "$file" && ! -L "$file" && -O "$file" ]] || refuse 'artifact must be a regular file'
    [[ -n "$(find "$file" -prune -type f -links 1)" ]] || refuse 'artifact must have one link'
    [[ "$(wc -c < "$file")" -eq "$size" ]] || refuse 'artifact size differs from the reviewed asset'
    [[ "$(sha256_of < "$file")" = "$digest" ]] || refuse 'artifact digest differs from the reviewed asset'
}

acquire() {
    if [[ -e "$destination" ]]; then verify "$destination" "$expected_sha" "$expected_size"; return; fi
    held="$(mktemp -d "$directory/.spc-download.XXXXXX")"
    trap 'rm -rf -- "$held"' EXIT
    trap 'exit 1' HUP INT TERM
    local url="$release_url" status attempt
    for attempt in 1 2 3 4; do
        [[ ! "$url" =~ [[:space:][:cntrl:]] && "$url" != *'#'* ]] || refuse 'invalid redirect URL'
        case "$url" in
            "$release_url"|https://release-assets.githubusercontent.com/*|https://objects.githubusercontent.com/*) ;;
            *) refuse 'redirect host or scheme is outside the reviewed set' ;;
        esac
        # Only the downloader child inherits this file-size limit. Build
        # outputs and dependency sources can legitimately exceed the archive.
        status="$(ulimit -f 24576; exec curl --disable --proto '=https' --tlsv1.2 --silent --show-error --fail \
            --max-time 45 --connect-timeout 10 --max-filesize "$archive_size" \
            --dump-header "$held/headers" --output "$held/artifact" --write-out '%{http_code}' "$url")" || refuse 'download failed'
        case "$status" in
            200)
                verify "$held/artifact" "$archive_sha" "$archive_size"
                [[ "$(tar -tzf "$held/artifact")" = spc ]] || refuse 'archive member differs'
                tar -xzOf "$held/artifact" spc > "$held/spc" || refuse 'archive extraction failed'
                verify "$held/spc" "$expected_sha" "$expected_size"
                spc_python publish "$platform" "$held/spc" "$destination"
                printf 'SPC %s %s acquired: asset=%s sha256=%s bytes=%s\n' "$version" "$platform" "$asset_id" "$expected_sha" "$expected_size"
                return ;;
            301|302|303|307|308)
                url="$(awk 'tolower($0) ~ /^location:/ { sub(/^[^:]*:[ \t]*/, ""); sub(/\r$/, ""); value=$0; count++ }
                    END { if (count != 1) exit 1; print value }' "$held/headers")" || refuse 'redirect requires one Location header' ;;
            *) refuse 'unexpected HTTP status' ;;
        esac
    done
    refuse 'redirect limit exceeded'
}
acquire
if [[ "$role" = run ]]; then
    shift 3
    spc_python run "$platform" "$destination" "$@"
fi
