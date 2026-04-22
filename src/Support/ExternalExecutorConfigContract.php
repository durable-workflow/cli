<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Support;

final class ExternalExecutorConfigContract
{
    public const SCHEMA = 'durable-workflow.external-executor.config';

    public const VERSION = 1;

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    public static function validate(array $config): array
    {
        $errors = [];

        if (($config['schema'] ?? null) !== self::SCHEMA) {
            $errors[] = sprintf('schema must be [%s]', self::SCHEMA);
        }

        if (($config['version'] ?? null) !== self::VERSION) {
            $errors[] = sprintf('version must be [%d]', self::VERSION);
        }

        $authRefs = self::objectMap($config['auth_refs'] ?? []);
        foreach ($authRefs as $name => $authRef) {
            array_push($errors, ...self::validateAuthRef($name, $authRef));
        }

        foreach (self::referencedAuthRefs($config) as $context => $ref) {
            if (! array_key_exists($ref, $authRefs)) {
                $errors[] = sprintf('%s references unknown auth_ref [%s]', $context, $ref);
            }
        }

        $carriers = self::objectMap($config['carriers'] ?? []);
        if ($carriers === []) {
            $errors[] = 'carriers must contain at least one carrier';
        }
        foreach ($carriers as $name => $carrier) {
            array_push($errors, ...self::validateCarrier($name, $carrier));
        }

        $mappingNames = [];
        foreach (self::list($config['mappings'] ?? []) as $index => $mapping) {
            if (! is_array($mapping)) {
                $errors[] = sprintf('mapping[%d] must be an object', $index);

                continue;
            }

            $name = self::string($mapping['name'] ?? null);
            if ($name !== null) {
                if (array_key_exists($name, $mappingNames)) {
                    $errors[] = sprintf('duplicate mapping name [%s]', $name);
                }

                $mappingNames[$name] = true;
            }

            $carrier = self::string($mapping['carrier'] ?? null);
            if ($carrier !== null && ! array_key_exists($carrier, $carriers)) {
                $errors[] = sprintf('mapping[%s] references unknown carrier [%s]', $name ?? (string) $index, $carrier);
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function assertValid(array $config): void
    {
        $errors = self::validate($config);

        if ($errors !== []) {
            throw new \InvalidArgumentException('Invalid external executor config: '.implode('; ', $errors));
        }
    }

    /**
     * @param  array<string, mixed>  $authRef
     * @return list<string>
     */
    private static function validateAuthRef(string $name, array $authRef): array
    {
        $type = self::string($authRef['type'] ?? null);
        if ($type === null) {
            return [sprintf('auth_ref [%s] is missing type', $name)];
        }

        return match ($type) {
            'profile' => self::requireOnly($name, $authRef, ['profile']),
            'env' => self::requireOnly($name, $authRef, ['env']),
            'token_file' => self::requireOnly($name, $authRef, ['path']),
            'mtls' => self::requireOnly($name, $authRef, ['cert', 'key_ref']),
            'signed_headers' => self::validateSignedHeaders($name, $authRef),
            default => [sprintf('auth_ref [%s] has unsupported type [%s]', $name, $type)],
        };
    }

    /**
     * @param  array<string, mixed>  $authRef
     * @param  list<string>  $required
     * @return list<string>
     */
    private static function requireOnly(string $name, array $authRef, array $required): array
    {
        $errors = [];

        foreach ($required as $field) {
            if ($field === 'header_allowlist') {
                if (! is_array($authRef[$field] ?? null)) {
                    $errors[] = sprintf('auth_ref [%s] requires %s', $name, $field);
                }

                continue;
            }

            if (self::string($authRef[$field] ?? null) === null) {
                $errors[] = sprintf('auth_ref [%s] requires %s', $name, $field);
            }
        }

        foreach (['profile', 'env', 'path', 'cert', 'key', 'key_ref', 'header_allowlist'] as $field) {
            if (! in_array($field, $required, true) && array_key_exists($field, $authRef)) {
                $errors[] = sprintf('auth_ref [%s] type [%s] must not persist %s', $name, $authRef['type'], $field);
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $authRef
     * @return list<string>
     */
    private static function validateSignedHeaders(string $name, array $authRef): array
    {
        $errors = self::requireOnly($name, $authRef, ['key_ref', 'header_allowlist']);
        $allowlist = $authRef['header_allowlist'] ?? null;

        if (! is_array($allowlist) || $allowlist === []) {
            $errors[] = sprintf('auth_ref [%s] requires non-empty header_allowlist', $name);

            return $errors;
        }

        foreach ($allowlist as $header) {
            if (! is_string($header) || trim($header) === '') {
                $errors[] = sprintf('auth_ref [%s] header_allowlist entries must be non-empty strings', $name);
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $carrier
     * @return list<string>
     */
    private static function validateCarrier(string $name, array $carrier): array
    {
        $type = self::string($carrier['type'] ?? null);

        if ($type !== 'invocable_http') {
            return [];
        }

        $errors = [];
        $capabilities = $carrier['capabilities'] ?? null;

        if (is_array($capabilities)) {
            $invalid = [];
            foreach ($capabilities as $capability) {
                if ($capability !== 'activity_task') {
                    $invalid[] = (string) $capability;
                }
            }

            if ($invalid !== []) {
                $errors[] = sprintf(
                    'carrier [%s] type [invocable_http] only supports activity_task capability',
                    $name,
                );
            }
        }

        $url = self::string($carrier['url'] ?? null);
        if ($url === null) {
            $errors[] = sprintf('carrier [%s] type [invocable_http] requires url', $name);
        } else {
            array_push($errors, ...self::validateInvocableUrl($name, $url));
        }

        $method = strtoupper(self::string($carrier['method'] ?? 'POST') ?? 'POST');
        if ($method !== 'POST') {
            $errors[] = sprintf('carrier [%s] type [invocable_http] only supports POST method', $name);
        }

        if (array_key_exists('timeout_seconds', $carrier)) {
            $timeout = $carrier['timeout_seconds'];
            if (! is_int($timeout) || $timeout < 1 || $timeout > 900) {
                $errors[] = sprintf(
                    'carrier [%s] type [invocable_http] timeout_seconds must be an integer between 1 and 900',
                    $name,
                );
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private static function validateInvocableUrl(string $name, string $url): array
    {
        $parts = parse_url($url);

        if (! is_array($parts) || self::string($parts['scheme'] ?? null) === null || self::string($parts['host'] ?? null) === null) {
            return [sprintf('carrier [%s] type [invocable_http] url must be absolute', $name)];
        }

        if (array_key_exists('user', $parts) || array_key_exists('pass', $parts)) {
            return [sprintf('carrier [%s] type [invocable_http] url must not include credentials', $name)];
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);

        if ($scheme === 'https') {
            return [];
        }

        if ($scheme === 'http' && self::isLoopbackHost($host)) {
            return [];
        }

        return [sprintf(
            'carrier [%s] type [invocable_http] url must use https, except http loopback for local development',
            $name,
        )];
    }

    private static function isLoopbackHost(string $host): bool
    {
        return in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || str_starts_with($host, '127.');
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, string>
     */
    private static function referencedAuthRefs(array $config): array
    {
        $refs = [];
        $defaultRef = self::string(is_array($config['defaults'] ?? null) ? ($config['defaults']['auth_ref'] ?? null) : null);

        if ($defaultRef !== null) {
            $refs['defaults.auth_ref'] = $defaultRef;
        }

        foreach (self::list($config['mappings'] ?? []) as $index => $mapping) {
            if (! is_array($mapping)) {
                continue;
            }

            $ref = self::string($mapping['auth_ref'] ?? null);
            if ($ref !== null) {
                $name = self::string($mapping['name'] ?? null) ?? (string) $index;
                $refs[sprintf('mapping[%s].auth_ref', $name)] = $ref;
            }
        }

        return $refs;
    }

    /**
     * @param  mixed  $value
     * @return array<string, array<string, mixed>>
     */
    private static function objectMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $map = [];
        foreach ($value as $key => $entry) {
            if (is_string($key) && is_array($entry)) {
                $map[$key] = $entry;
            }
        }

        return $map;
    }

    /**
     * @param  mixed  $value
     * @return list<mixed>
     */
    private static function list(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values($value);
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
