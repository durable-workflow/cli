#!/usr/bin/env bash
# Immutable upstream SPC v2 release archives. These are acquisition identities,
# not qualification of the PHP/dependency inputs downloaded by SPC itself.
set -euo pipefail
umask 077
export LC_ALL=C
# Archive/config environment must not supply extraction commands or curl URLs.
unset TAR_OPTIONS GZIP GZIP_OPT

platform="${1:?Usage: ensure-spc.sh PLATFORM ABSOLUTE_DESTINATION}"
destination="${2:?Usage: ensure-spc.sh PLATFORM ABSOLUTE_DESTINATION}"
version=2.8.5
case "$platform" in
    linux-x86_64)
        asset_id=399146238
        archive_sha=523ba4279c54c7a377156c0dd3a36adf92ee64b01e9a7f5e9e2ec084b8e458e5
        archive_size=4170453
        expected_sha=d1e8815bbf6b1c98324d3a0c93af3a40b9ba1e4a1af4021921d19d14b244afcd
        expected_size=4378067 ;;
    linux-aarch64)
        asset_id=399146236
        archive_sha=675a3840dcdc4ed041fe20eaa54310ce019a9984c1c03951df9ec66df5795213
        archive_size=4301050
        expected_sha=e82524fa737790c8189eb8211a53298e63ccd81b8b87f776c9fa45180d62c43b
        expected_size=4526270 ;;
    macos-x86_64)
        asset_id=399146531
        archive_sha=e8b798048f62ca4960764196543b60ae703f7174aa418824cf542aeec1d2cd6a
        archive_size=5024260
        expected_sha=c5f1ec6285fc259e4a081909183f1f458ffdfbf4ab05b097fc082bd21130d43c
        expected_size=9232412 ;;
    macos-aarch64)
        asset_id=399146400
        archive_sha=acf2f25d56d0cbf8e65aa82e5054fef555f7be7c5c38046c6e0819f266d83225
        archive_size=4916396
        expected_sha=f21cd8e5032a27000369470909fbe724fa81d7b41e1de35fa9288e6115a557e1
        expected_size=9222300 ;;
    *) echo 'SPC acquisition refused: platform has no reviewed artifact identity' >&2; exit 1 ;;
esac

refuse() {
    echo "SPC acquisition refused: $1" >&2
    exit 1
}

[[ "$destination" = /* ]] || refuse 'destination must be absolute'
[[ ! -L "$destination" ]] || refuse 'cached artifact must not be a symlink'
directory="$(dirname "$destination")"
mkdir -p "$directory"
[[ "$(cd "$directory" && pwd -P)" = "$directory" ]] || refuse 'cache directory must be canonical'

verify() {
    local file="$1" digest="$2" size="$3" actual
    [[ -f "$file" && ! -L "$file" && -O "$file" ]] || refuse 'artifact must be a regular file'
    [[ -n "$(find "$file" -prune -type f -links 1)" ]] || refuse 'artifact must have one link'
    [[ "$(wc -c < "$file")" -eq "$size" ]] || refuse 'artifact size differs from the reviewed asset'
    if command -v sha256sum >/dev/null 2>&1; then
        actual="$(sha256sum "$file" | awk '{print $1}')"
    else
        actual="$(shasum -a 256 "$file" | awk '{print $1}')"
    fi
    [[ "$actual" = "$digest" ]] || refuse 'artifact digest differs from the reviewed asset'
}

if [[ -e "$destination" ]]; then
    verify "$destination" "$expected_sha" "$expected_size"
    chmod 0755 "$destination"
    exit 0
fi

held="$(mktemp -d "$directory/.spc-download.XXXXXX")"
trap 'rm -rf -- "$held"' EXIT
trap 'exit 1' HUP INT TERM
# Also bound header-file growth; this child-only ceiling exceeds the asset.
ulimit -f 24576
release_url="https://github.com/crazywhalecc/static-php-cli/releases/download/$version/spc-$platform.tar.gz"
url="$release_url"
for attempt in 1 2 3 4; do
    # Check every destination before contacting it. Never use curl -L here.
    [[ ! "$url" =~ [[:space:][:cntrl:]] && "$url" != *'#'* ]] || refuse 'invalid redirect URL'
    case "$url" in
        "$release_url"|\
        https://release-assets.githubusercontent.com/*|https://objects.githubusercontent.com/*) ;;
        *) refuse 'redirect host or scheme is outside the reviewed set' ;;
    esac
    status="$(curl --disable --proto '=https' --tlsv1.2 --silent --show-error --fail \
        --max-time 45 --connect-timeout 10 --max-filesize "$archive_size" \
        --dump-header "$held/headers" --output "$held/artifact" \
        --write-out '%{http_code}' "$url")" || refuse 'download failed'
    case "$status" in
        200)
            verify "$held/artifact" "$archive_sha" "$archive_size"
            # Each exact reviewed archive has one regular member named spc.
            # Never extract paths to disk or trust an unverified archive.
            [[ "$(tar -tzf "$held/artifact")" = spc ]] || refuse 'archive member differs'
            tar -xzOf "$held/artifact" spc > "$held/spc" || refuse 'archive extraction failed'
            verify "$held/spc" "$expected_sha" "$expected_size"
            chmod 0755 "$held/spc"
            [[ ! -e "$destination" && ! -L "$destination" ]] || refuse 'cache appeared during download'
            mv -- "$held/spc" "$destination"
            printf 'SPC %s %s verified: asset=%s sha256=%s bytes=%s\n' "$version" "$platform" "$asset_id" "$expected_sha" "$expected_size"
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
