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

        return [
            'server' => [
                'value' => $this->server,
                'source' => $this->sourceName('server', 'default'),
            ],
            'namespace' => [
                'value' => $this->namespace,
                'source' => $this->sourceName('namespace', 'default'),
            ],
            'profile' => [
                'name' => $this->profile?->name,
                'source' => $this->sourceName('profile', null),
                'config_path' => $configPath,
            ],
            'auth' => [
                'type' => 'bearer_token',
                'present' => $this->token !== null,
                'source' => $tokenSource['source'] ?? 'none',
                'env' => $tokenSource['env'] ?? null,
                'profile' => $tokenSource['profile'] ?? null,
                'value' => $this->token !== null ? 'redacted' : null,
            ],
            'tls' => [
                'verify' => $this->tlsVerify,
                'source' => $this->sourceName('tls_verify', 'default'),
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
}
