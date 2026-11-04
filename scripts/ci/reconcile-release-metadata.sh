#!/usr/bin/env bash

set -euo pipefail

fail() {
    printf 'Release metadata reconciliation failed: %s\n' "$1" >&2
    exit 1
}

raw_tag="${1:-}"
repository="${GITHUB_REPOSITORY:-}"
gh_cli="${GH_CLI:-gh}"
script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"

tag="$(node "$script_dir/release-version.js" normalize "$raw_tag" 2>/dev/null)" \
    || fail 'release tag must be exact SemVer'
[[ "$repository" =~ ^[0-9A-Za-z_.-]+/[0-9A-Za-z_.-]+$ ]] \
    || fail 'GITHUB_REPOSITORY must identify the release repository'
# A release never takes the latest marker from a newer one.
latest_known=false
if current_latest="$("$gh_cli" api "repos/$repository/releases/latest" --jq '.tag_name' 2>/dev/null)" &&
    latest_metadata="$(node "$script_dir/release-version.js" metadata "$current_latest" 2>/dev/null)" &&
    [ "$latest_metadata" = "false true" ]; then
    latest_known=true
fi
metadata="$(node "$script_dir/release-version.js" metadata "$tag" "$current_latest")" \
    || fail 'release metadata could not be derived from the tag'
read -r prerelease make_latest <<< "$metadata"
if [ "$latest_known" != "true" ]; then
    make_latest=false
fi

release_id="$(
    "$gh_cli" api "repos/$repository/releases/tags/$tag" --jq '.id' 2>/dev/null
)" || fail "public GitHub Release $tag does not exist"
[[ "$release_id" =~ ^[1-9][0-9]*$ ]] \
    || fail "public GitHub Release $tag returned an invalid identity"

"$gh_cli" api --method PATCH "repos/$repository/releases/$release_id" \
    -F "prerelease=$prerelease" \
    -f "make_latest=$make_latest" \
    --silent

printf 'Reconciled GitHub Release %s with prerelease=%s and make_latest=%s.\n' \
    "$tag" "$prerelease" "$make_latest"
