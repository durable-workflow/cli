<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Support;

/**
 * Merges the three sources of dw connection settings — explicit
 * command-line flags, environment variables, and named profiles —
 * into a single resolved connection descriptor.
 *
 * Precedence (highest wins):
 *   1. Command-line flag (`--server`, `--namespace`, `--token`).
 *   2. Environment variable (DURABLE_WORKFLOW_SERVER_URL / _NAMESPACE / _AUTH_TOKEN).
 *   3. Named profile (selected via `--env`, `DW_ENV`, or `current_env`).
 *   4. Built-in default (server=http://localhost:8080, namespace=default,
 *      no token, TLS verification on).
 *
 * Profile selection rules (hard-fail branch of the feature):
 *   - `--env <name>`       → hard error if profile does not exist.
 *   - `DW_ENV` env var     → hard error if profile does not exist.
 *   - `current_env` in config → hard error if profile does not exist (the
 *     user explicitly set it with `dw env:use`; a missing profile means
 *     the config has drifted and silent fallback would hide the problem).
 *   - No selection signal  → no profile applied; env/defaults win.
 *
 * The resolver never writes to disk. It simply reads the store and
 * produces a ResolvedConnection the ServerClient can consume.
 */
final class ProfileResolver
{
    public function __construct(private readonly ProfileStore $store) {}

    public function resolve(
        ?string $flagEnv,
        ?string $flagServer,
        ?string $flagNamespace,
        ?string $flagToken,
    ): ResolvedConnection {
        $profile = $this->selectProfile($flagEnv);

        $server = $flagServer
            ?? self::envString('DURABLE_WORKFLOW_SERVER_URL')
            ?? $profile?->server
            ?? 'http://localhost:8080';

        $namespace = $flagNamespace
            ?? self::envString('DURABLE_WORKFLOW_NAMESPACE')
            ?? $profile?->namespace
            ?? 'default';

        $token = $flagToken
            ?? self::envString('DURABLE_WORKFLOW_AUTH_TOKEN')
            ?? $profile?->resolveToken();

        return new ResolvedConnection(
            server: $server,
            namespace: $namespace,
            token: $token,
            tlsVerify: $profile?->tlsVerify ?? true,
            profile: $profile,
        );
    }

    /**
     * Look up the profile specified by `--env`, `DW_ENV`, or
     * `current_env`, hard-failing if any explicit selection points
     * at a missing profile.
     */
    private function selectProfile(?string $flagEnv): ?Profile
    {
        if ($flagEnv !== null && $flagEnv !== '') {
            return $this->store->requireProfile($flagEnv, '--env');
        }

        $envName = self::envString('DW_ENV');
        if ($envName !== null) {
            return $this->store->requireProfile($envName, 'DW_ENV');
        }

        $current = $this->store->currentEnvName();
        if ($current !== null) {
            return $this->store->requireProfile($current, 'dw env:use (current_env in config)');
        }

        return null;
    }

    private static function envString(string $name): ?string
    {
        $value = $_ENV[$name] ?? getenv($name);
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}
