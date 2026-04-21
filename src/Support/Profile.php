<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Support;

/**
 * One named environment profile — endpoint, namespace, token source, TLS,
 * and default output format — as stored in the on-disk config.
 *
 * Profiles are always created via the ProfileStore so the shape stays
 * consistent across writes, reads, and --json exposure. Token sources
 * are kept indirect (env-var names) by default so credentials do not
 * sit in plaintext in the config file.
 */
final class Profile
{
    public const TOKEN_SOURCE_LITERAL = 'literal';

    public const TOKEN_SOURCE_ENV = 'env';

    public const OUTPUT_MODES = [OutputMode::TABLE, OutputMode::JSON, OutputMode::JSONL];

    /**
     * @param  array{type: 'literal'|'env', value: string}|null  $tokenSource
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $server = null,
        public readonly ?string $namespace = null,
        public readonly ?array $tokenSource = null,
        public readonly bool $tlsVerify = true,
        public readonly ?string $output = null,
    ) {
        if ($output !== null && ! in_array($output, self::OUTPUT_MODES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Profile output must be one of [%s], got [%s].',
                implode(', ', self::OUTPUT_MODES),
                $output,
            ));
        }

        if ($tokenSource !== null) {
            $type = $tokenSource['type'] ?? null;
            $value = $tokenSource['value'] ?? null;

            if (! in_array($type, [self::TOKEN_SOURCE_LITERAL, self::TOKEN_SOURCE_ENV], true)) {
                throw new \InvalidArgumentException(sprintf(
                    'Profile token source type must be [%s] or [%s], got [%s].',
                    self::TOKEN_SOURCE_LITERAL,
                    self::TOKEN_SOURCE_ENV,
                    is_scalar($type) ? (string) $type : 'non-scalar',
                ));
            }

            if (! is_string($value) || $value === '') {
                throw new \InvalidArgumentException('Profile token source value must be a non-empty string.');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(string $name, array $data): self
    {
        $tokenSource = null;
        if (isset($data['token_source']) && is_array($data['token_source'])) {
            $type = $data['token_source']['type'] ?? null;
            $value = $data['token_source']['value'] ?? null;

            if (is_string($type) && is_string($value) && $value !== '') {
                $tokenSource = ['type' => $type, 'value' => $value];
            }
        }

        return new self(
            name: $name,
            server: self::stringOrNull($data['server'] ?? null),
            namespace: self::stringOrNull($data['namespace'] ?? null),
            tokenSource: $tokenSource,
            tlsVerify: (bool) ($data['tls_verify'] ?? true),
            output: self::stringOrNull($data['output'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [];

        if ($this->server !== null) {
            $data['server'] = $this->server;
        }
        if ($this->namespace !== null) {
            $data['namespace'] = $this->namespace;
        }
        if ($this->tokenSource !== null) {
            $data['token_source'] = $this->tokenSource;
        }
        $data['tls_verify'] = $this->tlsVerify;
        if ($this->output !== null) {
            $data['output'] = $this->output;
        }

        return $data;
    }

    /**
     * Redacted-by-default view for list/show/--json. Callers that
     * explicitly pass showToken=true (operator intent: `dw env:show
     * <name> --show-token`) get the literal value; every other path
     * gets a safe description of where the token comes from.
     *
     * @return array<string, mixed>
     */
    public function describe(bool $showToken = false): array
    {
        $data = [
            'name' => $this->name,
            'server' => $this->server,
            'namespace' => $this->namespace,
            'tls_verify' => $this->tlsVerify,
            'output' => $this->output,
        ];

        if ($this->tokenSource === null) {
            $data['token_source'] = null;

            return $data;
        }

        if ($this->tokenSource['type'] === self::TOKEN_SOURCE_ENV) {
            $data['token_source'] = [
                'type' => self::TOKEN_SOURCE_ENV,
                'env' => $this->tokenSource['value'],
            ];

            return $data;
        }

        $data['token_source'] = [
            'type' => self::TOKEN_SOURCE_LITERAL,
            'value' => $showToken ? $this->tokenSource['value'] : 'redacted',
        ];

        return $data;
    }

    /**
     * Resolve the effective bearer token at invocation time.
     * Returns null when the profile has no token source or the
     * referenced env var is unset/empty.
     */
    public function resolveToken(): ?string
    {
        if ($this->tokenSource === null) {
            return null;
        }

        if ($this->tokenSource['type'] === self::TOKEN_SOURCE_LITERAL) {
            return $this->tokenSource['value'];
        }

        $name = $this->tokenSource['value'];
        $value = $_ENV[$name] ?? getenv($name);

        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}
