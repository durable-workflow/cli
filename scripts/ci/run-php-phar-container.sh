#!/usr/bin/env bash
set -euo pipefail

command="${0##*/}"
case "$command" in php|composer|box) ;; *) echo 'Invoke this PHAR launcher as php, composer or box.' >&2; exit 2 ;; esac
root="${CLI_PHP_CI_ROOT:?PHP CI repository root is required}"
state="${CLI_PHP_CI_STATE:?PHP CI state directory is required}"
image="${CLI_PHP_CI_IMAGE:?PHP CI image is required}"
if [[ ! "$image" =~ ^sha256:[0-9a-f]{64}$ ]]; then
    echo 'PHP CI must use the inspected immutable image config ID.' >&2
    exit 1
fi

options=(--rm --init --interactive --name "cli-php-ci-${command}-${BASHPID}"
    --cpus 2 --memory 2g --pids-limit 512 --network host
    --cap-drop ALL --security-opt no-new-privileges
    --user "$(id -u):$(id -g)" --workdir "$PWD"
    --env "HOME=$state/home"
    --mount "type=bind,source=$root,target=$root,readonly"
    --mount "type=bind,source=$state,target=$state,readonly")

# Keep host-executed launchers and controls read-only. Only dependency data,
# test output and private runtime caches can be changed by application code.
for directory in "$root/vendor" "$root/build" "$state/home" "$state/tmp" "$state/composer-home"; do
    if [[ ! -d "$directory" || -L "$directory" || "$(realpath "$directory")" != "$directory" ]]; then
        echo 'PHP CI writable directories must be existing real paths.' >&2
        exit 1
    fi
    options+=(--mount "type=bind,source=$directory,target=$directory")
done
generated="$root/src/GeneratedBuildInfo.php"
[[ -f "$generated" && ! -L "$generated" && -O "$generated" && "$(realpath "$generated")" = "$generated" ]] || {
    echo 'The generated build-info output must be a real owned file.' >&2; exit 1;
}
[[ -n "$(find "$generated" -prune -type f -links 1)" ]] || exit 1
options+=(--mount "type=bind,source=$generated,target=$generated"
    --mount "type=bind,source=$root/docker/php-ci/phar.ini,target=/usr/local/etc/php/conf.d/zz-cli-phar.ini,readonly")

# The Composer verifier is a composite action outside the checkout.
if [[ -n "${GITHUB_ACTION_PATH:-}" ]]; then
    options+=(--mount "type=bind,source=$GITHUB_ACTION_PATH,target=$GITHUB_ACTION_PATH,readonly")
fi
git_directory="$(git -C "$root" rev-parse --path-format=absolute --git-common-dir)"
if [[ "$git_directory" != "$root/"* ]]; then
    options+=(--mount "type=bind,source=$git_directory,target=$git_directory,readonly")
fi

declare -A mounted=()
for variable in SSL_CERT_FILE CURL_CA_BUNDLE GIT_SSL_CAINFO COMPOSER_CAFILE \
    REQUESTS_CA_BUNDLE PIP_CERT NPM_CONFIG_CAFILE CARGO_HTTP_CAINFO CARGO_HTTP_PROXY_CAINFO \
    SSL_CERT_DIR GIT_SSL_CAPATH COMPOSER_CAPATH; do
    if [[ -n "${!variable:-}" ]]; then
        selected="${!variable}"
        if [[ -z "${mounted[$selected]:-}" ]]; then
            options+=(--mount "type=bind,source=$selected,target=$selected,readonly")
            mounted[$selected]=1
        fi
        options+=(--env "$variable")
    fi
done

# PHAR builds need no role credentials or PHP startup overrides.
while IFS= read -r variable; do
    case "$variable" in
        COMPOSER_HOME|COMPOSER_CACHE_DIR|COMPOSER_MAX_PARALLEL_*|COMPOSER_IPRESOLVE|\
        DW_CI_CA_SHA256|GITHUB_ACTIONS|CI|TMPDIR|TZ|SOURCE_DATE_EPOCH|\
        DW_CLI_VERSION|DW_CLI_COMMIT|DW_CLI_BUILD_DATE|GITHUB_SHA|GITHUB_REF_TYPE|GITHUB_REF_NAME)
            options+=(--env "$variable") ;;
        *) ;;
    esac
done < <(compgen -e)

# The host verifier must inspect the actual container Composer PHAR.
arguments=()
for argument in "$@"; do
    if [[ "$argument" == "$state/bin/composer" ]]; then
        argument=/usr/local/bin/composer
    fi
    arguments+=("$argument")
done
if [[ "$command" = box ]]; then
    exec docker run "${options[@]}" "$image" php "$root/scripts/ci/exec-box.php" "$state/artifacts/box.phar" "${arguments[@]}"
fi
exec docker run "${options[@]}" "$image" "$command" "${arguments[@]}"
