<?php

declare(strict_types=1);

namespace Tests\Support;

use DurableWorkflow\Cli\Support\ExternalTaskInputContract;
use PHPUnit\Framework\TestCase;

final class ExternalTaskInputContractTest extends TestCase
{
    public function test_activity_task_fixture_parses_carrier_neutral_input_envelope(): void
    {
        $taskInput = ExternalTaskInputContract::parseEnvelope(self::fixture('activity-task.v1.json'));

        self::assertTrue($taskInput->isActivityTask());
        self::assertFalse($taskInput->isWorkflowTask());
        self::assertSame('activity_task', $taskInput->kind);
        self::assertSame('attempt_01HV7D3KJ1C8WQNNY8MVM8J40X', $taskInput->task['activity_attempt_id']);
        self::assertSame('billing.charge-card', $taskInput->task['handler']);
        self::assertNull($taskInput->task['connection']);
        self::assertSame('invoice-2026-0001', $taskInput->workflow['id']);
        self::assertStringEndsWith('/heartbeat', $taskInput->lease['heartbeat_endpoint']);
        self::assertSame('2026-04-22T01:04:00.000000Z', $taskInput->deadlines['heartbeat'] ?? null);
        self::assertNull($taskInput->history);
    }

    public function test_workflow_task_fixture_parses_history_and_resume_context(): void
    {
        $taskInput = ExternalTaskInputContract::parseEnvelope(self::fixture('workflow-task.v1.json'));

        self::assertTrue($taskInput->isWorkflowTask());
        self::assertSame('workflow_task', $taskInput->kind);
        self::assertSame(2, $taskInput->task['attempt']);
        self::assertSame('build-2026-04-22', $taskInput->task['compatibility']);
        self::assertSame('running', $taskInput->workflow['status']);
        self::assertSame(42, $taskInput->workflow['resume']['workflow_sequence']);
        self::assertSame(42, $taskInput->history['last_sequence'] ?? null);
        self::assertNull($taskInput->deadlines);
    }

    public function test_cluster_info_fixture_artifact_validates_sha_and_embedded_example(): void
    {
        $taskInput = ExternalTaskInputContract::parseArtifact(self::artifactFor(
            'activity-task.v1',
            self::fixture('activity-task.v1.json'),
        ));

        self::assertSame('activity_task', $taskInput->kind);
        self::assertSame('attempt_01HV7D3KJ1C8WQNNY8MVM8J40X', $taskInput->task['idempotency_key']);
    }

    public function test_validates_embedded_input_fixture_artifact_manifest(): void
    {
        $manifest = self::manifest();

        self::assertSame([], ExternalTaskInputContract::validateManifest($manifest));
        self::assertSame(['workflow_task', 'activity_task'], ExternalTaskInputContract::fixtureNames());
    }

    public function test_rejects_missing_scope_and_incomplete_fixture_contracts(): void
    {
        $manifest = self::manifest();
        unset($manifest['scope']['activity_grade_external_execution'], $manifest['fixtures']['workflow_task']);
        $manifest['fixtures']['activity_task']['path'] = 'tests/fixtures/external-task-input/activity-task.v1.json';
        $manifest['fixtures']['activity_task']['sha256'] = 'not-the-example-hash';

        $errors = ExternalTaskInputContract::validateManifest($manifest);

        self::assertContains(
            'scope.activity_grade_external_execution must include fixture [activity_task]',
            $errors,
        );
        self::assertContains('missing fixture [workflow_task]', $errors);
        self::assertContains('fixture [activity_task] sha256 does not match embedded example', $errors);
    }

    public function test_warns_from_cluster_info_when_required_input_artifacts_are_missing(): void
    {
        $clusterInfo = [
            'worker_protocol' => [
                'version' => '1.0',
                'external_task_input_contract' => [
                    'schema' => ExternalTaskInputContract::CONTRACT_SCHEMA,
                    'version' => ExternalTaskInputContract::VERSION,
                    'fixtures' => [],
                ],
            ],
        ];

        self::assertSame(
            'Compatibility warning: server worker_protocol.external_task_input_contract is missing consumable fixture artifact coverage: scope must describe activity_grade_external_execution and worker_protocol_runtime.',
            ExternalTaskInputContract::warning($clusterInfo),
        );
    }

    public function test_fixture_artifact_rejects_checksum_drift(): void
    {
        $artifact = self::artifactFor('activity-task.v1', self::fixture('activity-task.v1.json'));
        $artifact['example']['task']['handler'] = 'billing.refund-card';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('sha256 mismatch');

        ExternalTaskInputContract::parseArtifact($artifact);
    }

    public function test_unknown_optional_fields_are_ignored(): void
    {
        $envelope = self::fixture('activity-task.v1.json');
        $envelope['carrier_debug'] = ['ignored' => true];
        $envelope['task']['carrier_debug'] = 'ignored';

        $taskInput = ExternalTaskInputContract::parseEnvelope($envelope);

        self::assertSame('activity_task', $taskInput->kind);
    }

    public function test_missing_required_nested_fields_are_rejected(): void
    {
        $envelope = self::fixture('workflow-task.v1.json');
        unset($envelope['history']['last_sequence']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('last_sequence');

        ExternalTaskInputContract::parseEnvelope($envelope);
    }

    /**
     * @dataProvider schemaAndVersionProvider
     */
    public function test_schema_and_version_are_pinned(string $field, string|int $value): void
    {
        $envelope = self::fixture('activity-task.v1.json');
        $envelope[$field] = $value;

        $this->expectException(\InvalidArgumentException::class);

        ExternalTaskInputContract::parseEnvelope($envelope);
    }

    public function test_activity_attempts_must_be_positive(): void
    {
        $envelope = self::fixture('activity-task.v1.json');
        $envelope['task']['attempt'] = 0;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('attempt must be >= 1');

        ExternalTaskInputContract::parseEnvelope($envelope);
    }

    /**
     * @return iterable<string, array{string, string|int}>
     */
    public static function schemaAndVersionProvider(): iterable
    {
        yield 'schema' => ['schema', 'wrong.schema'];
        yield 'version' => ['version', 2];
    }

    /**
     * @return array<string, mixed>
     */
    public static function manifest(): array
    {
        return [
            'schema' => ExternalTaskInputContract::CONTRACT_SCHEMA,
            'version' => ExternalTaskInputContract::VERSION,
            'scope' => [
                'activity_grade_external_execution' => [
                    'task_kinds' => ['activity_task'],
                    'fixture_keys' => ['activity_task'],
                ],
                'worker_protocol_runtime' => [
                    'task_kinds' => ['workflow_task'],
                    'fixture_keys' => ['workflow_task'],
                ],
            ],
            'fixtures' => [
                'workflow_task' => self::artifactFor(
                    'workflow-task.v1',
                    self::fixture('workflow-task.v1.json'),
                ),
                'activity_task' => self::artifactFor(
                    'activity-task.v1',
                    self::fixture('activity-task.v1.json'),
                ),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function fixture(string $name): array
    {
        $contents = file_get_contents(__DIR__.'/../fixtures/external-task-input/'.$name);
        self::assertIsString($contents);

        $fixture = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($fixture);

        return $fixture;
    }

    /**
     * @param  array<string, mixed>  $example
     * @return array<string, mixed>
     */
    private static function artifactFor(string $name, array $example): array
    {
        return [
            'artifact' => 'durable-workflow.v2.external-task-input.'.$name,
            'media_type' => ExternalTaskInputContract::MEDIA_TYPE,
            'schema' => ExternalTaskInputContract::SCHEMA,
            'version' => ExternalTaskInputContract::VERSION,
            'sha256' => hash('sha256', json_encode($example, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            'example' => $example,
        ];
    }
}
