<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Support;

final class ExternalTaskResultContract
{
    public const SCHEMA = 'durable-workflow.v2.external-task-result.contract';

    public const VERSION = 1;

    public const ENVELOPE_SCHEMA = 'durable-workflow.v2.external-task-result';

    public const MEDIA_TYPE = 'application/vnd.durable-workflow.external-task-result+json';

    private const REQUIRED_FIXTURES = [
        'success' => 'durable-workflow.v2.external-task-result.success.v1',
        'failure' => 'durable-workflow.v2.external-task-result.failure.v1',
        'malformed_output' => 'durable-workflow.v2.external-task-result.malformed-output.v1',
        'cancellation' => 'durable-workflow.v2.external-task-result.cancellation.v1',
        'handler_crash' => 'durable-workflow.v2.external-task-result.handler-crash.v1',
        'decode_failure' => 'durable-workflow.v2.external-task-result.decode-failure.v1',
        'unsupported_payload_codec' => 'durable-workflow.v2.external-task-result.unsupported-payload-codec.v1',
        'unsupported_payload_reference' => 'durable-workflow.v2.external-task-result.unsupported-payload-reference.v1',
    ];

    /**
     * @return list<string>
     */
    public static function fixtureNames(): array
    {
        return array_keys(self::REQUIRED_FIXTURES);
    }

    public static function expected(): string
    {
        return sprintf('%s v%d', self::SCHEMA, self::VERSION);
    }

    /**
     * @param  array<string, mixed>  $clusterInfo
     */
    public static function warning(array $clusterInfo): ?string
    {
        if (! self::serverRequiresContract($clusterInfo) && ! self::hasPublishedManifest($clusterInfo)) {
            return null;
        }

        $workerProtocol = $clusterInfo['worker_protocol'] ?? null;
        $manifest = is_array($workerProtocol) ? $workerProtocol['external_task_result_contract'] ?? null : null;

        if (! is_array($manifest)) {
            return sprintf(
                'Compatibility warning: server did not advertise worker_protocol.external_task_result_contract; dw CLI expects %s.',
                self::expected(),
            );
        }

        $schema = $manifest['schema'] ?? null;
        $version = $manifest['version'] ?? null;
        if ($schema !== self::SCHEMA || ! self::versionMatches($version)) {
            return sprintf(
                'Compatibility warning: server advertises worker_protocol.external_task_result_contract [%s v%s]; dw CLI expects %s.',
                is_scalar($schema) ? (string) $schema : 'missing',
                is_scalar($version) ? (string) $version : 'missing',
                self::expected(),
            );
        }

        $errors = self::validateManifest($manifest);
        if ($errors !== []) {
            return sprintf(
                'Compatibility warning: server worker_protocol.external_task_result_contract is missing consumable fixture artifact coverage: %s.',
                $errors[0],
            );
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $clusterInfo
     * @return array<string, mixed>|null
     */
    public static function diagnostic(array $clusterInfo): ?array
    {
        $workerProtocol = $clusterInfo['worker_protocol'] ?? null;
        $manifest = is_array($workerProtocol) ? $workerProtocol['external_task_result_contract'] ?? null : null;

        if (! is_array($manifest)) {
            return null;
        }

        $fixtures = is_array($manifest['fixtures'] ?? null) ? $manifest['fixtures'] : [];

        return [
            'schema' => is_scalar($manifest['schema'] ?? null) ? (string) $manifest['schema'] : null,
            'version' => is_scalar($manifest['version'] ?? null) ? (int) $manifest['version'] : null,
            'fixtures' => array_values(array_intersect(array_keys($fixtures), self::fixtureNames())),
            'valid' => self::validateManifest($manifest) === [],
        ];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<string>
     */
    public static function validateManifest(array $manifest): array
    {
        $errors = [];
        $fixtures = $manifest['fixtures'] ?? null;

        if (! is_array($fixtures)) {
            return ['fixtures must be an object keyed by artifact role'];
        }

        foreach (self::REQUIRED_FIXTURES as $name => $artifactName) {
            $artifact = $fixtures[$name] ?? null;
            if (! is_array($artifact)) {
                $errors[] = sprintf('missing fixture [%s]', $name);

                continue;
            }

            $errors = array_merge($errors, self::validateArtifact($name, $artifactName, $artifact));
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $clusterInfo
     */
    private static function hasPublishedManifest(array $clusterInfo): bool
    {
        $workerProtocol = $clusterInfo['worker_protocol'] ?? null;

        return is_array($workerProtocol) && array_key_exists('external_task_result_contract', $workerProtocol);
    }

    /**
     * @param  array<string, mixed>  $clusterInfo
     */
    private static function serverRequiresContract(array $clusterInfo): bool
    {
        $compatibility = $clusterInfo['client_compatibility'] ?? null;
        if (! is_array($compatibility)) {
            return false;
        }

        $requiredProtocols = $compatibility['required_protocols'] ?? null;
        if (is_array($requiredProtocols)) {
            $workerProtocol = $requiredProtocols['worker_protocol'] ?? null;
            if (is_array($workerProtocol) && array_key_exists('external_task_result_contract', $workerProtocol)) {
                return true;
            }
        }

        $clients = $compatibility['clients'] ?? null;
        $cli = is_array($clients) ? $clients['cli'] ?? null : null;
        $requires = is_array($cli) ? $cli['requires'] ?? null : null;
        if (! is_array($requires)) {
            return false;
        }

        foreach ($requires as $requirement) {
            if (is_string($requirement) && str_starts_with($requirement, 'worker_protocol.external_task_result_contract')) {
                return true;
            }
        }

        return false;
    }

    private static function versionMatches(mixed $version): bool
    {
        return is_int($version) || (is_string($version) && ctype_digit($version))
            ? (int) $version === self::VERSION
            : false;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return list<string>
     */
    private static function validateArtifact(string $name, string $artifactName, array $artifact): array
    {
        $errors = [];

        if (($artifact['artifact'] ?? null) !== $artifactName) {
            $errors[] = sprintf('fixture [%s] has artifact [%s]', $name, self::display($artifact['artifact'] ?? null));
        }

        if (($artifact['media_type'] ?? null) !== self::MEDIA_TYPE) {
            $errors[] = sprintf('fixture [%s] has media type [%s]', $name, self::display($artifact['media_type'] ?? null));
        }

        if (($artifact['schema'] ?? null) !== self::ENVELOPE_SCHEMA || ! self::versionMatches($artifact['version'] ?? null)) {
            $errors[] = sprintf('fixture [%s] has unsupported envelope schema/version', $name);
        }

        $example = $artifact['example'] ?? null;
        if (! is_array($example)) {
            $errors[] = sprintf('fixture [%s] is missing an embedded example', $name);

            return $errors;
        }

        $sha = hash('sha256', (string) json_encode($example, JSON_UNESCAPED_SLASHES));
        if (($artifact['sha256'] ?? null) !== $sha) {
            $errors[] = sprintf('fixture [%s] sha256 does not match embedded example', $name);
        }

        return array_merge($errors, self::validateExample($name, $example));
    }

    /**
     * @param  array<string, mixed>  $example
     * @return list<string>
     */
    private static function validateExample(string $name, array $example): array
    {
        $errors = [];

        if (($example['schema'] ?? null) !== self::ENVELOPE_SCHEMA || ! self::versionMatches($example['version'] ?? null)) {
            $errors[] = sprintf('fixture [%s] example has unsupported envelope schema/version', $name);
        }

        $outcome = $example['outcome'] ?? null;
        if (! is_array($outcome)) {
            $errors[] = sprintf('fixture [%s] example is missing outcome object', $name);

            return $errors;
        }

        $status = $outcome['status'] ?? null;
        if ($name === 'success') {
            if ($status !== 'succeeded' || ! array_key_exists('result', $example)) {
                $errors[] = 'fixture [success] must be a succeeded envelope with result';
            }

            return $errors;
        }

        if ($status !== 'failed' || ! is_array($example['failure'] ?? null)) {
            $errors[] = sprintf('fixture [%s] must be a failed envelope with failure object', $name);

            return $errors;
        }

        $failure = $example['failure'];
        if (! in_array($failure['kind'] ?? null, self::failureKinds(), true)) {
            $errors[] = sprintf('fixture [%s] has unsupported failure kind [%s]', $name, self::display($failure['kind'] ?? null));
        }

        if (! in_array($failure['classification'] ?? null, self::failureClassifications(), true)) {
            $errors[] = sprintf(
                'fixture [%s] has unsupported failure classification [%s]',
                $name,
                self::display($failure['classification'] ?? null),
            );
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private static function failureKinds(): array
    {
        return [
            'application',
            'timeout',
            'cancellation',
            'malformed_output',
            'handler_crash',
            'decode_failure',
            'unsupported_payload',
        ];
    }

    /**
     * @return list<string>
     */
    private static function failureClassifications(): array
    {
        return [
            'application_error',
            'timeout',
            'cancelled',
            'deadline_exceeded',
            'handler_crash',
            'decode_failure',
            'malformed_output',
            'unsupported_payload_codec',
            'unsupported_payload_reference',
        ];
    }

    private static function display(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : 'missing';
    }
}
