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
        $selection = $this->selectProfile($flagEnv);
        $profile = $selection['profile'];

        [$server, $serverSource] = $this->resolveString(
            flagValue: $flagServer,
            envName: 'DURABLE_WORKFLOW_SERVER_URL',
            profileValue: $profile?->server,
            defaultValue: 'http://localhost:8080',
        );

        [$namespace, $namespaceSource] = $this->resolveString(
            flagValue: $flagNamespace,
            envName: 'DURABLE_WORKFLOW_NAMESPACE',
            profileValue: $profile?->namespace,
            defaultValue: 'default',
        );

        [$token, $tokenSource] = $this->resolveToken($flagToken, $profile);

        return new ResolvedConnection(
            server: $server,
            namespace: $namespace,
            token: $token,
            tlsVerify: $profile?->tlsVerify ?? true,
            profile: $profile,
            sources: [
                'server' => $serverSource,
                'namespace' => $namespaceSource,
                'profile' => $selection['source'],
                'token' => $tokenSource,
                'tls_verify' => $profile instanceof Profile ? 'profile' : 'default',
            ],
        );
    }

    /**
     * Look up the profile specified by `--env`, `DW_ENV`, or
     * `current_env`, hard-failing if any explicit selection points
     * at a missing profile.
     *
     * @return array{profile: Profile|null, source: string|null}
     */
    private function selectProfile(?string $flagEnv): array
    {
        if ($flagEnv !== null && $flagEnv !== '') {
            return [
                'profile' => $this->store->requireProfile($flagEnv, '--env'),
                'source' => 'flag',
            ];
        }

        $envName = self::envString('DW_ENV');
        if ($envName !== null) {
            return [
                'profile' => $this->store->requireProfile($envName, 'DW_ENV'),
                'source' => 'DW_ENV',
            ];
        }

        $current = $this->store->currentEnvName();
        if ($current !== null) {
            return [
                'profile' => $this->store->requireProfile($current, 'dw env:use (current_env in config)'),
                'source' => 'current_env',
            ];
        }

        return [
            'profile' => null,
            'source' => null,
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveString(
        ?string $flagValue,
        string $envName,
        ?string $profileValue,
        string $defaultValue,
    ): array {
        if ($flagValue !== null) {
            return [$flagValue, 'flag'];
        }

        $envValue = self::envString($envName);
        if ($envValue !== null) {
            return [$envValue, $envName];
        }

        if ($profileValue !== null) {
            return [$profileValue, 'profile'];
        }

        return [$defaultValue, 'default'];
    }

    /**
     * @return array{0: string|null, 1: array{source: string, env?: string|null, profile?: string|null}}
     */
    private function resolveToken(?string $flagToken, ?Profile $profile): array
    {
        if ($flagToken !== null) {
            return [
                $flagToken,
                [
                    'source' => 'flag',
                    'env' => null,
                    'profile' => null,
                ],
            ];
        }

        $envToken = self::envString('DURABLE_WORKFLOW_AUTH_TOKEN');
        if ($envToken !== null) {
            return [
                $envToken,
                [
                    'source' => 'DURABLE_WORKFLOW_AUTH_TOKEN',
                    'env' => 'DURABLE_WORKFLOW_AUTH_TOKEN',
                    'profile' => null,
                ],
            ];
        }

        $tokenSource = $profile?->tokenSource;
        if (is_array($tokenSource)) {
            if (($tokenSource['type'] ?? null) === Profile::TOKEN_SOURCE_ENV) {
                return [
                    $profile?->resolveToken(),
                    [
                        'source' => 'profile_env',
                        'env' => is_string($tokenSource['value'] ?? null) ? $tokenSource['value'] : null,
                        'profile' => $profile?->name,
                    ],
                ];
            }

            return [
                $profile?->resolveToken(),
                [
                    'source' => 'profile_literal',
                    'env' => null,
                    'profile' => $profile?->name,
                ],
            ];
        }

        return [
            null,
            [
                'source' => 'none',
                'env' => null,
                'profile' => null,
            ],
        ];
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
