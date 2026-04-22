<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Support;

final class ExternalTaskInputContract
{
    public const SCHEMA = 'durable-workflow.v2.external-task-input';

    public const CONTRACT_SCHEMA = 'durable-workflow.v2.external-task-input.contract';

    public const MEDIA_TYPE = 'application/vnd.durable-workflow.external-task-input+json';

    public const VERSION = 1;

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
