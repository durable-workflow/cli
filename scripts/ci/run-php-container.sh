#!/usr/bin/env bash
set -euo pipefail

command="${0##*/}"
case "$command" in php|composer) ;; *) echo 'Invoke this launcher as php or composer.' >&2; exit 2 ;; esac
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
    --mount "type=bind,source=$root,target=$root"
    --mount "type=bind,source=$state,target=$state")

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

# Ordinary tests need no host credentials. The optional smoke fixture has
# explicit, separate role inputs; do not forward arbitrary DW_* or token env.
while IFS= read -r variable; do
    case "$variable" in
        COMPOSER_HOME|COMPOSER_CACHE_DIR|COMPOSER_MAX_PARALLEL_*|COMPOSER_IPRESOLVE|\
        DURABLE_WORKFLOW_CLI_SMOKE|DURABLE_WORKFLOW_CLI_SMOKE_SERVER_URL|\
        DURABLE_WORKFLOW_CLI_SMOKE_ADMIN_TOKEN|DURABLE_WORKFLOW_CLI_SMOKE_OPERATOR_TOKEN|\
        DURABLE_WORKFLOW_CLI_SMOKE_WORKER_TOKEN|\
        DW_CI_CA_SHA256|GITHUB_ACTIONS|CI|TMPDIR|TZ)
            options+=(--env "$variable") ;;
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
exec docker run "${options[@]}" "$image" "$command" "${arguments[@]}"
