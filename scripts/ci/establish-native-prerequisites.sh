#!/usr/bin/env bash

set -euo pipefail

platform="${1:-}"
architecture="${2:-}"
spc="${3:-./spc}"
cache_directory="${4:-native-prerequisite-cache}"
evidence_path="${NATIVE_PREREQUISITE_EVIDENCE:-native-prerequisite-evidence.json}"
output_path="${GITHUB_OUTPUT:-/dev/null}"

mode="not-applicable"
source_name="not-applicable"
prerequisite_identity="not-applicable"

write_evidence() {
    local outcome="$1"
    node - \
        "$evidence_path" \
        "$platform" \
        "$architecture" \
        "$outcome" \
        "$mode" \
        "$source_name" \
        "$prerequisite_identity" <<'NODE'
const fs = require('fs');

const [
  path,
  platform,
  architecture,
  outcome,
  mode,
  source,
  identity,
] = process.argv.slice(2);

fs.writeFileSync(path, `${JSON.stringify({
  schema: 'durable-workflow.cli.native-prerequisite/v1',
  platform,
  architecture,
  outcome,
  cache_mode: mode,
  source,
  identity,
}, null, 2)}\n`);
NODE
}

unsupported_platform() {
    write_evidence "unsupported_platform"
    printf '%s\n' \
        "::error title=Unsupported native prerequisite platform::Native prerequisites are not supported on ${platform}/${architecture}" >&2
    exit 64
}

case "${platform}/${architecture}" in
    Linux/X64|Linux/ARM64|macOS/ARM64) ;;
    *) unsupported_platform ;;
esac

if [ ! -x "$spc" ]; then
    write_evidence "spc_unavailable"
    printf '%s\n' "::error::Pinned static-php-cli executable is unavailable: $spc" >&2
    exit 66
fi

if [ "$platform" = "Linux" ]; then
    musl_version="${MUSL_VERSION:-}"
    musl_sha256="${MUSL_SHA256:-}"
    fetch_attempts="${NATIVE_PREREQUISITE_FETCH_ATTEMPTS:-3}"
    curl_retries="${NATIVE_PREREQUISITE_CURL_RETRIES:-3}"
    connect_timeout="${NATIVE_PREREQUISITE_CONNECT_TIMEOUT:-10}"
    retry_delay="${NATIVE_PREREQUISITE_RETRY_DELAY:-5}"

    case "$musl_version" in
        ''|*[!0-9.]*)
            printf '%s\n' "::error::MUSL_VERSION must contain only digits and dots" >&2
            exit 64
            ;;
    esac
    if ! printf '%s\n' "$musl_sha256" | grep -Eq '^[0-9a-f]{64}$'; then
        printf '%s\n' "::error::MUSL_SHA256 must be a lowercase SHA-256 digest" >&2
        exit 64
    fi
    for value_name in fetch_attempts curl_retries connect_timeout retry_delay; do
        value="${!value_name}"
        case "$value" in
            ''|*[!0-9]*)
                printf '%s\n' "::error::${value_name} must be a non-negative integer" >&2
                exit 64
                ;;
        esac
    done
    if [ "$fetch_attempts" -lt 1 ]; then
        printf '%s\n' "::error::fetch_attempts must be at least 1" >&2
        exit 64
    fi

    sha256_file() {
        if command -v sha256sum >/dev/null 2>&1; then
            sha256sum "$1" | awk '{print $1}'
        else
            shasum -a 256 "$1" | awk '{print $1}'
        fi
    }

    sha1_file() {
        if command -v sha1sum >/dev/null 2>&1; then
            sha1sum "$1" | awk '{print $1}'
        else
            shasum -a 1 "$1" | awk '{print $1}'
        fi
    }

    verify_archive() {
        local archive="$1"
        [ -s "$archive" ] && [ "$(sha256_file "$archive")" = "$musl_sha256" ]
    }

    archive_name="musl-${musl_version}.tar.gz"
    prerequisite_identity="musl-${musl_version}-sha256:${musl_sha256}"
    mkdir -p "$cache_directory"
    cached_archive="${cache_directory}/${archive_name}"

    if verify_archive "$cached_archive"; then
        mode="warm"
        source_name="trusted-cache"
    else
        if [ -e "$cached_archive" ]; then
            printf '%s\n' "::warning::Discarding native prerequisite cache entry with a mismatched checksum"
            rm -f "$cached_archive"
        fi

        # Alpine retains a byte-identical upstream distfile independently of
        # the live musl origin. Either source must match the pinned digest.
        default_sources="https://distfiles.alpinelinux.org/distfiles/v3.21/${archive_name}
https://musl.libc.org/releases/${archive_name}"
        mapfile -t sources <<< "${MUSL_SOURCE_URLS:-$default_sources}"
        downloaded=false
        for source_url in "${sources[@]}"; do
            [ -n "$source_url" ] || continue
            attempt=1
            while [ "$attempt" -le "$fetch_attempts" ]; do
                candidate="${cached_archive}.partial"
                rm -f "$candidate"
                if curl --fail --silent --show-error --location \
                    --retry "$curl_retries" --retry-all-errors \
                    --connect-timeout "$connect_timeout" \
                    --output "$candidate" "$source_url"; then
                    if verify_archive "$candidate"; then
                        mv "$candidate" "$cached_archive"
                        mode="cold"
                        source_name="$source_url"
                        downloaded=true
                        break
                    fi
                    printf '%s\n' \
                        "::warning::Rejected native prerequisite from ${source_url}: SHA-256 mismatch"
                else
                    printf '%s\n' \
                        "::warning::Native prerequisite fetch ${attempt}/${fetch_attempts} failed from ${source_url}"
                fi
                rm -f "$candidate"
                if [ "$attempt" -lt "$fetch_attempts" ] && [ "$retry_delay" -gt 0 ]; then
                    sleep "$retry_delay"
                fi
                attempt=$((attempt + 1))
            done
            [ "$downloaded" = "true" ] && break
        done

        if [ "$downloaded" != "true" ]; then
            mode="cold"
            source_name="exhausted"
            write_evidence "fetch_exhausted"
            printf '%s\n' \
                "::error title=Native prerequisite fetch exhausted::Verified musl ${musl_version} could not be fetched from any pinned source" >&2
            exit 69
        fi
    fi

    mkdir -p downloads
    cp "$cached_archive" "downloads/${archive_name}"
    # static-php-cli's local lock format records SHA-1. Trust is established
    # by the pinned SHA-256 check above; this value only makes doctor reuse it.
    musl_sha1="$(sha1_file "downloads/${archive_name}")"
    node - "downloads/.lock.json" "musl-${musl_version}" "$archive_name" "$musl_sha1" <<'NODE'
const fs = require('fs');

const [path, name, filename, hash] = process.argv.slice(2);
fs.writeFileSync(path, `${JSON.stringify({
  [name]: {
    source_type: 'archive',
    filename,
    move_path: null,
    lock_as: 1,
    hash,
  },
}, null, 2)}\n`);
NODE
else
    mode="system"
    source_name="runner-package-manager"
    prerequisite_identity="system-${platform}-${architecture}"
fi

if ! "$spc" doctor --auto-fix; then
    write_evidence "doctor_failed"
    printf '%s\n' \
        "::error title=Native prerequisite doctor failed::static-php-cli could not establish verified prerequisites on ${platform}/${architecture}" >&2
    exit 70
fi

write_evidence "success"
{
    printf 'identity=%s\n' "$prerequisite_identity"
    printf 'mode=%s\n' "$mode"
    printf 'source=%s\n' "$source_name"
} >> "$output_path"
