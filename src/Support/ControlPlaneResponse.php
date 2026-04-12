<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Support;

final class ControlPlaneResponse
{
    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public static function normalize(string $method, string $path, array $body, int $statusCode): array
    {
        $contract = $body['control_plane'] ?? null;

        if (! is_array($contract)) {
            throw new \RuntimeException(sprintf(
                'Server error: control-plane response for [%s] is missing the shared control-plane contract.',
                $path,
            ));
        }

        $definition = self::definition($path, $contract);

        self::assertNoLegacyFields($path, $body, self::legacyFields($path, $definition));
        self::assertContract($path, $contract, $definition, $body, $statusCode);

        $body['control_plane_schema'] ??= $contract['schema'] ?? null;
        $body['control_plane_schema_version'] ??= $contract['version'] ?? null;
        $body['control_plane_operation'] ??= $contract['operation'] ?? null;

        foreach ($contract as $field => $value) {
            if (in_array($field, ['schema', 'version', 'contract'], true)) {
                continue;
            }

            if (! array_key_exists($field, $body)) {
                $body[$field] = $value;
            }
        }

        $nameField = self::stringValue($contract['operation_name_field'] ?? null);

        if (
            $nameField !== null
            && ! array_key_exists($nameField, $body)
            && array_key_exists('operation_name', $contract)
        ) {
            $body[$nameField] = $contract['operation_name'];
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private static function assertNoLegacyFields(string $path, array $body, array $legacyFields): void
    {
        foreach ($legacyFields as $legacy => $canonical) {
            if (! array_key_exists($legacy, $body)) {
                continue;
            }

            throw new \RuntimeException(sprintf(
                'Server error: non-canonical control-plane field [%s] received for [%s]; expected [%s].',
                $legacy,
                $path,
                $canonical,
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $contract
     * @param  array<string, mixed>  $body
     */
    private static function assertContract(
        string $path,
        array $contract,
        array $definition,
        array $body,
        int $statusCode,
    ): void {
        self::requireNonEmptyString(
            $path,
            $contract['schema'] ?? null,
            'invalid control-plane contract schema',
        );
        self::requirePositiveInt(
            $path,
            $contract['version'] ?? null,
            'invalid control-plane contract version',
        );
        self::requireNonEmptyString(
            $path,
            $contract['operation'] ?? null,
            'control-plane contract is missing required field [operation]',
        );

        self::assertRequiredFields(
            $path,
            $contract,
            self::stringList($path, $definition['required_fields'] ?? null, 'required_fields'),
        );
        self::assertBodyConsistency($path, $contract, $body);

        if ($statusCode >= 400) {
            return;
        }

        foreach (self::stringList($path, $definition['success_fields'] ?? null, 'success_fields') as $field) {
            if (! array_key_exists($field, $contract)) {
                throw new \RuntimeException(sprintf(
                    'Server error: control-plane contract for [%s] is missing required field [%s].',
                    $path,
                    $field,
                ));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $contract
     */
    private static function assertRequiredFields(string $path, array $contract, array $fields): void
    {
        foreach ($fields as $field) {
            $value = $contract[$field] ?? null;

            if (! is_string($value) || trim($value) === '') {
                throw new \RuntimeException(sprintf(
                    'Server error: control-plane contract for [%s] is missing required field [%s].',
                    $path,
                    $field,
                ));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $contract
     * @param  array<string, mixed>  $body
     */
    private static function assertBodyConsistency(string $path, array $contract, array $body): void
    {
        self::assertMatchingField($path, 'workflow_id', $contract, $body);
        self::assertMatchingField($path, 'run_id', $contract, $body);

        $nameField = self::stringValue($contract['operation_name_field'] ?? null);

        if ($nameField === null) {
            return;
        }

        self::assertMatchingField($path, $nameField, [
            $nameField => $contract['operation_name'] ?? null,
        ], $body);
    }

    /**
     * @param  array<string, mixed>  $contract
     * @param  array<string, mixed>  $body
     */
    private static function assertMatchingField(string $path, string $field, array $contract, array $body): void
    {
        $contractValue = self::stringValue($contract[$field] ?? null);
        $bodyValue = self::stringValue($body[$field] ?? null);

        if ($contractValue === null || $bodyValue === null || $contractValue === $bodyValue) {
            return;
        }

        throw new \RuntimeException(sprintf(
            'Server error: control-plane contract for [%s] reported [%s=%s], but the response body reported [%s=%s].',
            $path,
            $field,
            $contractValue,
            $field,
            $bodyValue,
        ));
    }

    /**
     * @param  array<string, mixed>  $contract
     * @return array<string, mixed>
     */
    private static function definition(string $path, array $contract): array
    {
        $definition = $contract['contract'] ?? null;

        if (! is_array($definition)) {
            throw new \RuntimeException(sprintf(
                'Server error: control-plane response for [%s] is missing the shared control-plane contract definition.',
                $path,
            ));
        }

        self::requireNonEmptyString(
            $path,
            $definition['schema'] ?? null,
            'invalid nested control-plane contract schema',
        );
        self::requirePositiveInt(
            $path,
            $definition['version'] ?? null,
            'invalid nested control-plane contract version',
        );
        self::requireNonEmptyString(
            $path,
            $definition['legacy_field_policy'] ?? null,
            'invalid nested control-plane legacy field policy',
        );

        return $definition;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, string>
     */
    private static function legacyFields(string $path, array $definition): array
    {
        $legacyFields = $definition['legacy_fields'] ?? null;

        if (! is_array($legacyFields)) {
            throw new \RuntimeException(sprintf(
                'Server error: control-plane contract definition for [%s] is missing required field [legacy_fields].',
                $path,
            ));
        }

        foreach ($legacyFields as $legacy => $canonical) {
            if (! is_string($legacy) || trim($legacy) === '' || ! is_string($canonical) || trim($canonical) === '') {
                throw new \RuntimeException(sprintf(
                    'Server error: control-plane contract definition for [%s] contains an invalid legacy field mapping.',
                    $path,
                ));
            }
        }

        /** @var array<string, string> $legacyFields */
        return $legacyFields;
    }

    /**
     * @return list<string>
     */
    private static function stringList(string $path, mixed $value, string $field): array
    {
        if (! is_array($value)) {
            throw new \RuntimeException(sprintf(
                'Server error: control-plane contract definition for [%s] is missing required field [%s].',
                $path,
                $field,
            ));
        }

        $values = [];

        foreach ($value as $entry) {
            if (! is_string($entry) || trim($entry) === '') {
                throw new \RuntimeException(sprintf(
                    'Server error: control-plane contract definition for [%s] contains an invalid [%s] entry.',
                    $path,
                    $field,
                ));
            }

            $values[] = trim($entry);
        }

        return $values;
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }

    private static function requireNonEmptyString(
        string $path,
        mixed $value,
        string $message,
    ): string {
        $resolved = self::stringValue($value);

        if ($resolved !== null) {
            return $resolved;
        }

        throw new \RuntimeException(sprintf(
            'Server error: %s for [%s].',
            $message,
            $path,
        ));
    }

    private static function requirePositiveInt(
        string $path,
        mixed $value,
        string $message,
    ): int {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        throw new \RuntimeException(sprintf(
            'Server error: %s for [%s].',
            $message,
            $path,
        ));
    }
}
