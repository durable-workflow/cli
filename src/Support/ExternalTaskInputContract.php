<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Support;

final class ExternalTaskInputContract
{
    public const SCHEMA = 'durable-workflow.v2.external-task-input';

    public const CONTRACT_SCHEMA = 'durable-workflow.v2.external-task-input.contract';

    public const MEDIA_TYPE = 'application/vnd.durable-workflow.external-task-input+json';

    public const VERSION = 1;

    private const REQUIRED_FIXTURES = [
        'workflow_task' => 'durable-workflow.v2.external-task-input.workflow-task.v1',
        'activity_task' => 'durable-workflow.v2.external-task-input.activity-task.v1',
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
        return sprintf('%s v%d', self::CONTRACT_SCHEMA, self::VERSION);
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
        $manifest = is_array($workerProtocol) ? $workerProtocol['external_task_input_contract'] ?? null : null;

        if (! is_array($manifest)) {
            return sprintf(
                'Compatibility warning: server did not advertise worker_protocol.external_task_input_contract; dw CLI expects %s.',
                self::expected(),
            );
        }

        $schema = $manifest['schema'] ?? null;
        $version = $manifest['version'] ?? null;
        if ($schema !== self::CONTRACT_SCHEMA || ! self::versionMatches($version)) {
            return sprintf(
                'Compatibility warning: server advertises worker_protocol.external_task_input_contract [%s v%s]; dw CLI expects %s.',
                is_scalar($schema) ? (string) $schema : 'missing',
                is_scalar($version) ? (string) $version : 'missing',
                self::expected(),
            );
        }

        $errors = self::validateManifest($manifest);
        if ($errors !== []) {
            return sprintf(
                'Compatibility warning: server worker_protocol.external_task_input_contract is missing consumable fixture artifact coverage: %s.',
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
        $manifest = is_array($workerProtocol) ? $workerProtocol['external_task_input_contract'] ?? null : null;

        if (! is_array($manifest)) {
            return null;
        }

        $fixtures = is_array($manifest['fixtures'] ?? null) ? $manifest['fixtures'] : [];
        $scope = is_array($manifest['scope'] ?? null) ? $manifest['scope'] : [];

        return [
            'schema' => is_scalar($manifest['schema'] ?? null) ? (string) $manifest['schema'] : null,
            'version' => is_scalar($manifest['version'] ?? null) ? (int) $manifest['version'] : null,
            'fixtures' => array_values(array_intersect(array_keys($fixtures), self::fixtureNames())),
            'activity_grade_fixture_keys' => self::scopeFixtureKeys($scope, 'activity_grade_external_execution'),
            'worker_protocol_fixture_keys' => self::scopeFixtureKeys($scope, 'worker_protocol_runtime'),
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

        $errors = array_merge($errors, self::validateScope($manifest));

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
     * @param  array<string, mixed>  $envelope
     */
    public static function parseEnvelope(array $envelope): ExternalTaskInput
    {
        self::requireValue($envelope, 'schema', self::SCHEMA);
        self::requireValue($envelope, 'version', self::VERSION);

        $task = self::requireArray($envelope, 'task');
        $kind = self::requireKind($task);
        self::validateTask($task, $kind);

        $workflow = self::requireArray($envelope, 'workflow');
        self::validateWorkflow($workflow, $kind);

        $lease = self::requireArray($envelope, 'lease');
        self::requireString($lease, 'owner');
        self::requireString($lease, 'expires_at');
        self::requireString($lease, 'heartbeat_endpoint');

        $payloads = self::requireArray($envelope, 'payloads');
        self::requireNullableArray($payloads, 'arguments');

        $headers = self::requireArray($envelope, 'headers');

        if ($kind === 'activity_task') {
            $deadlines = self::requireArray($envelope, 'deadlines');

            foreach (['schedule_to_start', 'start_to_close', 'schedule_to_close', 'heartbeat'] as $field) {
                self::requireOptionalString($deadlines, $field);
            }

            return new ExternalTaskInput($kind, $task, $workflow, $lease, $payloads, $headers, deadlines: $deadlines);
        }

        $history = self::requireArray($envelope, 'history');
        self::requireList($history, 'events');
        self::requireInt($history, 'last_sequence');
        self::requireOptionalString($history, 'next_page_token');
        self::requireOptionalString($history, 'encoding');

        return new ExternalTaskInput($kind, $task, $workflow, $lease, $payloads, $headers, history: $history);
    }

    /**
     * @param  array<string, mixed>  $artifact
     */
    public static function parseArtifact(array $artifact): ExternalTaskInput
    {
        $artifactName = self::requireString($artifact, 'artifact');

        if (! str_starts_with($artifactName, 'durable-workflow.v2.external-task-input.')) {
            throw new \InvalidArgumentException(sprintf('Unsupported external task input artifact [%s].', $artifactName));
        }

        self::requireValue($artifact, 'media_type', self::MEDIA_TYPE);
        self::requireValue($artifact, 'schema', self::SCHEMA);
        self::requireValue($artifact, 'version', self::VERSION);

        $example = self::requireArray($artifact, 'example');
        $expectedSha = self::requireString($artifact, 'sha256');
        $actualSha = self::sha256Json($example);

        if ($actualSha !== $expectedSha) {
            throw new \InvalidArgumentException(sprintf(
                'External task input artifact [%s] sha256 mismatch: expected %s, got %s.',
                $artifactName,
                $expectedSha,
                $actualSha,
            ));
        }

        return self::parseEnvelope($example);
    }

    /**
     * @param  array<string, mixed>  $clusterInfo
     */
    private static function hasPublishedManifest(array $clusterInfo): bool
    {
        $workerProtocol = $clusterInfo['worker_protocol'] ?? null;

        return is_array($workerProtocol) && array_key_exists('external_task_input_contract', $workerProtocol);
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
            if (is_array($workerProtocol) && array_key_exists('external_task_input_contract', $workerProtocol)) {
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
            if (is_string($requirement) && str_starts_with($requirement, 'worker_protocol.external_task_input_contract')) {
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
     * @param  array<string, mixed>  $manifest
     * @return list<string>
     */
    private static function validateScope(array $manifest): array
    {
        $scope = $manifest['scope'] ?? null;
        if (! is_array($scope)) {
            return ['scope must describe activity_grade_external_execution and worker_protocol_runtime'];
        }

        $activityKeys = self::scopeFixtureKeys($scope, 'activity_grade_external_execution');
        $workerKeys = self::scopeFixtureKeys($scope, 'worker_protocol_runtime');
        $errors = [];

        if (! in_array('activity_task', $activityKeys, true)) {
            $errors[] = 'scope.activity_grade_external_execution must include fixture [activity_task]';
        }

        if (! in_array('workflow_task', $workerKeys, true)) {
            $errors[] = 'scope.worker_protocol_runtime must include fixture [workflow_task]';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $scope
     * @return list<string>
     */
    private static function scopeFixtureKeys(array $scope, string $scopeName): array
    {
        $section = $scope[$scopeName] ?? null;
        $keys = is_array($section) ? $section['fixture_keys'] ?? null : null;

        if (! is_array($keys)) {
            return [];
        }

        return array_values(array_filter($keys, static fn ($key): bool => is_string($key)));
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

        if (($artifact['schema'] ?? null) !== self::SCHEMA || ! self::versionMatches($artifact['version'] ?? null)) {
            $errors[] = sprintf('fixture [%s] has unsupported envelope schema/version', $name);
        }

        $example = $artifact['example'] ?? null;
        if (! is_array($example)) {
            $errors[] = sprintf('fixture [%s] is missing an embedded example', $name);

            return $errors;
        }

        $sha = self::sha256Json($example);
        if (($artifact['sha256'] ?? null) !== $sha) {
            $errors[] = sprintf('fixture [%s] sha256 does not match embedded example', $name);
        }

        try {
            $parsed = self::parseEnvelope($example);
            if ($parsed->kind !== $name) {
                $errors[] = sprintf('fixture [%s] example has task kind [%s]', $name, $parsed->kind);
            }
        } catch (\InvalidArgumentException $exception) {
            $errors[] = sprintf('fixture [%s] example is invalid: %s', $name, $exception->getMessage());
        }

        return $errors;
    }

    private static function display(mixed $value): string
    {
        if ($value === null) {
            return 'missing';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return get_debug_type($value);
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private static function validateTask(array $task, string $kind): void
    {
        self::requireString($task, 'id');
        $attempt = self::requireInt($task, 'attempt');

        if ($attempt < 1) {
            throw new \InvalidArgumentException('External task input task.attempt must be >= 1.');
        }

        self::requireString($task, 'task_queue');
        self::requireOptionalString($task, 'connection');
        self::requireString($task, 'idempotency_key');

        if ($kind === 'activity_task') {
            self::requireString($task, 'activity_attempt_id');
            self::requireString($task, 'handler');
            self::requireNullableArray($task, 'external_executor', required: false);

            return;
        }

        self::requireOptionalString($task, 'handler');
        self::requireOptionalString($task, 'compatibility');
    }

    /**
     * @param  array<string, mixed>  $workflow
     */
    private static function validateWorkflow(array $workflow, string $kind): void
    {
        self::requireString($workflow, 'id');
        self::requireString($workflow, 'run_id');

        if ($kind !== 'workflow_task') {
            return;
        }

        self::requireOptionalString($workflow, 'status');
        self::requireArray($workflow, 'resume');
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private static function requireKind(array $value): string
    {
        $kind = self::requireString($value, 'kind');

        if ($kind === 'activity_task' || $kind === 'workflow_task') {
            return $kind;
        }

        throw new \InvalidArgumentException(sprintf('Unsupported external task input kind [%s].', $kind));
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private static function requireArray(array $value, string $field): array
    {
        if (! array_key_exists($field, $value)) {
            throw new \InvalidArgumentException(sprintf('External task input is missing required field [%s].', $field));
        }

        $item = $value[$field];

        if (! is_array($item) || array_is_list($item)) {
            throw new \InvalidArgumentException(sprintf('External task input field [%s] must be an object.', $field));
        }

        return $item;
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>|null
     */
    private static function requireNullableArray(array $value, string $field, bool $required = true): ?array
    {
        if (! array_key_exists($field, $value)) {
            if (! $required) {
                return null;
            }

            throw new \InvalidArgumentException(sprintf('External task input is missing required field [%s].', $field));
        }

        $item = $value[$field];

        if ($item === null) {
            return null;
        }

        if (! is_array($item) || array_is_list($item)) {
            throw new \InvalidArgumentException(sprintf('External task input field [%s] must be an object or null.', $field));
        }

        return $item;
    }

    /**
     * @param  array<string, mixed>  $value
     * @return list<mixed>
     */
    private static function requireList(array $value, string $field): array
    {
        if (! array_key_exists($field, $value)) {
            throw new \InvalidArgumentException(sprintf('External task input is missing required field [%s].', $field));
        }

        $item = $value[$field];

        if (! is_array($item) || ! array_is_list($item)) {
            throw new \InvalidArgumentException(sprintf('External task input field [%s] must be an array.', $field));
        }

        return $item;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private static function requireString(array $value, string $field): string
    {
        if (! array_key_exists($field, $value)) {
            throw new \InvalidArgumentException(sprintf('External task input is missing required field [%s].', $field));
        }

        $item = $value[$field];

        if (! is_string($item)) {
            throw new \InvalidArgumentException(sprintf('External task input field [%s] must be a string.', $field));
        }

        return $item;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private static function requireOptionalString(array $value, string $field): ?string
    {
        if (! array_key_exists($field, $value) || $value[$field] === null) {
            return null;
        }

        if (! is_string($value[$field])) {
            throw new \InvalidArgumentException(sprintf('External task input field [%s] must be a string or null.', $field));
        }

        return $value[$field];
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private static function requireInt(array $value, string $field): int
    {
        if (! array_key_exists($field, $value)) {
            throw new \InvalidArgumentException(sprintf('External task input is missing required field [%s].', $field));
        }

        $item = $value[$field];

        if (! is_int($item)) {
            throw new \InvalidArgumentException(sprintf('External task input field [%s] must be an integer.', $field));
        }

        return $item;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private static function requireValue(array $value, string $field, string|int $expected): void
    {
        if (! array_key_exists($field, $value)) {
            throw new \InvalidArgumentException(sprintf('External task input is missing required field [%s].', $field));
        }

        if ($value[$field] !== $expected) {
            throw new \InvalidArgumentException(sprintf(
                'External task input field [%s] must be [%s].',
                $field,
                (string) $expected,
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private static function sha256Json(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
