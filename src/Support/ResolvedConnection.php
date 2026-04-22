<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Support;

/**
 * The effective server/namespace/token tuple a command should use
 * after flag > env > profile > default precedence has been applied.
 * See ProfileResolver for how each field is chosen.
 */
final class ResolvedConnection
{
    /**
     * @param  array<string, mixed>  $sources
     */
    public function __construct(
        public readonly string $server,
        public readonly string $namespace,
        public readonly ?string $token,
        public readonly bool $tlsVerify,
        public readonly ?Profile $profile,
        public readonly array $sources = [],
    ) {}

    /**
     * Machine-readable, redacted view of the connection after flag, env,
     * profile, and default precedence has been applied.
     *
     * @return array<string, mixed>
     */
    public function effectiveConfig(string $configPath): array
    {
        $tokenSource = $this->sourceDetail('token');
        $serverSource = $this->sourceName('server', 'default');
        $namespaceSource = $this->sourceName('namespace', 'default');
        $profileSource = $this->sourceName('profile', null);
        $tlsSource = $this->sourceName('tls_verify', 'default');
        $authSource = is_string($tokenSource['source'] ?? null) ? $tokenSource['source'] : 'none';
        $server = [
            'value' => $this->server,
            'source' => $serverSource,
            'contract_source' => self::contractSource($serverSource),
        ];

        return [
            'contract' => [
                'schema' => AuthCompositionContract::SCHEMA,
                'version' => AuthCompositionContract::VERSION,
            ],
            'server' => $server,
            'server_url' => $server,
            'namespace' => [
                'value' => $this->namespace,
                'source' => $namespaceSource,
                'contract_source' => self::contractSource($namespaceSource),
            ],
            'profile' => [
                'name' => $this->profile?->name,
                'source' => $profileSource,
                'contract_source' => self::profileContractSource($profileSource),
                'config_path' => $configPath,
            ],
            'auth' => [
                'type' => $this->token !== null ? 'token' : 'none',
                'transport' => $this->token !== null ? 'bearer_authorization_header' : null,
                'present' => $this->token !== null,
                'source' => $authSource,
                'contract_source' => self::authContractSource($authSource),
                'env' => $tokenSource['env'] ?? null,
                'profile' => $tokenSource['profile'] ?? null,
                'value' => $this->token !== null ? 'redacted' : null,
            ],
            'tls' => [
                'verify' => $this->tlsVerify,
                'source' => $tlsSource,
                'contract_source' => self::contractSource($tlsSource),
            ],
            'identity' => [
                'subject' => null,
                'roles' => [],
                'source' => 'server',
                'asserted' => false,
            ],
        ];
    }

    private function sourceName(string $key, ?string $default): ?string
    {
        $source = $this->sources[$key] ?? null;

        if (is_string($source)) {
            return $source;
        }

        if (is_array($source) && is_string($source['source'] ?? null)) {
            return $source['source'];
        }

        return $default;
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceDetail(string $key): array
    {
        $source = $this->sources[$key] ?? null;

        if (is_array($source)) {
            return $source;
        }

        if (is_string($source)) {
            return ['source' => $source];
        }

        return [];
    }

    private static function contractSource(?string $source): ?string
    {
        if ($source === null) {
            return null;
        }

        if ($source === 'profile') {
            return 'selected_profile';
        }

        if (str_starts_with($source, 'DURABLE_WORKFLOW_') || $source === 'DW_ENV') {
            return 'environment';
        }

        return $source;
    }

    private static function profileContractSource(?string $source): ?string
    {
        return match ($source) {
            'flag' => 'flag_env',
            'DW_ENV' => 'DW_ENV',
            'current_env' => 'current_profile',
            default => $source,
        };
    }

    private static function authContractSource(string $source): string
    {
        return match ($source) {
            'DURABLE_WORKFLOW_AUTH_TOKEN' => 'environment',
            'profile_literal' => 'selected_profile',
            default => $source,
        };
    }
}
