<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Support;

final class ControlPlaneRequestContract
{
    public const SCHEMA = 'durable-workflow.v2.control-plane-request.contract';

    public const VERSION = 1;

    /**
     * @param  array<string, mixed>  $operations
     */
    public function __construct(
        private readonly array $operations,
        private readonly string $schema = self::SCHEMA,
        private readonly int $version = self::VERSION,
    ) {
    }

    /**
     * @param  array<string, mixed>  $clusterInfo
     */
    public static function fromClusterInfo(array $clusterInfo): ?self
    {
        $controlPlane = $clusterInfo['control_plane'] ?? null;

        if (! is_array($controlPlane)) {
            return null;
        }

        return self::fromPublishedManifest($controlPlane['request_contract'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $clusterInfo
     */
    public static function compatibilityErrorFromClusterInfo(array $clusterInfo): string
    {
        $controlPlane = $clusterInfo['control_plane'] ?? null;

        if (! is_array($controlPlane)) {
            return self::compatibilityError(null);
        }

        return self::compatibilityError($controlPlane['request_contract'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    public function manifest(): array
    {
        return $this->operations;
    }

    public function schema(): string
    {
        return $this->schema;
    }

    public function version(): int
    {
        return $this->version;
    }

    /**
     * @return array<string, mixed>
     */
    public function operations(): array
    {
        return $this->operations;
    }

    /**
     * @return array<string, mixed>
     */
    public function publishedManifest(): array
    {
        return [
            'schema' => $this->schema,
            'version' => $this->version,
            'operations' => $this->operations,
        ];
    }

    public function assertCanonicalValue(
        string $operation,
        string $field,
        ?string $value,
        ?string $optionName = null,
    ): void {
        if ($value === null || trim($value) === '') {
            return;
        }

        $definition = $this->fieldDefinition($operation, $field);

        if (! is_array($definition)) {
            return;
        }

        $displayField = $optionName ?? $field;
        $rejectedAliases = $this->stringMap($definition['rejected_aliases'] ?? null);

        if (array_key_exists($value, $rejectedAliases)) {
            throw new \RuntimeException(sprintf(
                'Server contract rejects %s value [%s]; use [%s].',
                $displayField,
                $value,
                $rejectedAliases[$value],
            ));
        }

        $canonicalValues = $this->stringList($definition['canonical_values'] ?? null);

        if ($canonicalValues === [] || in_array($value, $canonicalValues, true)) {
            return;
        }

        throw new \RuntimeException(sprintf(
            'Server contract expects %s to be one of [%s]; got [%s].',
            $displayField,
            implode(', ', $canonicalValues),
            $value,
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fieldDefinition(string $operation, string $field): ?array
    {
        $operationDefinition = $this->operations[$operation] ?? null;

        if (! is_array($operationDefinition)) {
            return null;
        }

        $fields = $operationDefinition['fields'] ?? null;

        if (! is_array($fields)) {
            return null;
        }

        $fieldDefinition = $fields[$field] ?? null;

        return is_array($fieldDefinition)
            ? $fieldDefinition
            : null;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $values = [];

        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '') {
                continue;
            }

            $values[] = trim($item);
        }

        return $values;
    }

    /**
     * @return array<string, string>
     */
    private function stringMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $map = [];

        foreach ($value as $key => $item) {
            if (! is_string($key) || trim($key) === '' || ! is_string($item) || trim($item) === '') {
                continue;
            }

            $map[trim($key)] = trim($item);
        }

        return $map;
    }

    private static function compatibilityError(mixed $requestContract): string
    {
        $expected = sprintf('%s v%d', self::SCHEMA, self::VERSION);

        if (! is_array($requestContract)) {
            return sprintf(
                'Server compatibility error: missing control_plane.request_contract; expected %s.',
                $expected,
            );
        }

        $schema = $requestContract['schema'] ?? null;
        $version = $requestContract['version'] ?? null;
        $operations = $requestContract['operations'] ?? null;

        if (! is_string($schema) || trim($schema) === '' || (! is_int($version) && ! ctype_digit((string) $version))) {
            return sprintf(
                'Server compatibility error: invalid control_plane.request_contract metadata; expected %s.',
                $expected,
            );
        }

        if ($schema !== self::SCHEMA || (int) $version !== self::VERSION) {
            return sprintf(
                'Server compatibility error: unsupported control_plane.request_contract schema/version [%s v%s]; expected %s.',
                $schema,
                (string) $version,
                $expected,
            );
        }

        if (! is_array($operations)) {
            return sprintf(
                'Server compatibility error: invalid control_plane.request_contract.operations payload; expected %s.',
                $expected,
            );
        }

        return sprintf(
            'Server compatibility error: invalid control_plane.request_contract payload; expected %s.',
            $expected,
        );
    }

    private static function fromPublishedManifest(mixed $requestContract): ?self
    {
        if (! is_array($requestContract)) {
            return null;
        }

        $schema = $requestContract['schema'] ?? null;
        $version = $requestContract['version'] ?? null;
        $operations = $requestContract['operations'] ?? null;

        if ($schema !== self::SCHEMA || (int) $version !== self::VERSION || ! is_array($operations)) {
            return null;
        }

        return new self($operations, $schema, (int) $version);
    }
}
