<?php

declare(strict_types=1);

namespace Tests\Support;

use DurableWorkflow\Cli\Support\ExternalTaskResultContract;
use PHPUnit\Framework\TestCase;

final class ExternalTaskResultContractTest extends TestCase
{
    public function test_validates_embedded_result_fixture_artifact_manifest(): void
    {
        $manifest = self::manifest();

        self::assertSame([], ExternalTaskResultContract::validateManifest($manifest));
        self::assertSame(
            [
                'success',
                'failure',
                'malformed_output',
                'cancellation',
                'handler_crash',
                'decode_failure',
                'unsupported_payload_codec',
                'unsupported_payload_reference',
            ],
            ExternalTaskResultContract::fixtureNames(),
        );
    }

    public function test_rejects_repo_path_style_or_incomplete_fixture_contracts(): void
    {
        $manifest = self::manifest();
        unset($manifest['fixtures']['decode_failure']);
        $manifest['fixtures']['success']['path'] = 'tests/Fixtures/contracts/external-task-result/success.v1.json';
        $manifest['fixtures']['success']['sha256'] = 'not-the-example-hash';

        $errors = ExternalTaskResultContract::validateManifest($manifest);

        self::assertContains('fixture [success] sha256 does not match embedded example', $errors);
        self::assertContains('missing fixture [decode_failure]', $errors);
    }

    public function test_warns_from_cluster_info_when_required_artifacts_are_missing(): void
    {
        $clusterInfo = [
            'worker_protocol' => [
                'version' => '1.0',
                'external_task_result_contract' => [
                    'schema' => ExternalTaskResultContract::SCHEMA,
                    'version' => ExternalTaskResultContract::VERSION,
                    'fixtures' => [],
                ],
            ],
        ];

        self::assertSame(
            'Compatibility warning: server worker_protocol.external_task_result_contract is missing consumable fixture artifact coverage: missing fixture [success].',
            ExternalTaskResultContract::warning($clusterInfo),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function manifest(): array
    {
        return [
            'schema' => ExternalTaskResultContract::SCHEMA,
            'version' => ExternalTaskResultContract::VERSION,
            'fixtures' => [
                'success' => self::fixtureArtifact(
                    'durable-workflow.v2.external-task-result.success.v1',
                    self::successExample(),
                ),
                'failure' => self::fixtureArtifact(
                    'durable-workflow.v2.external-task-result.failure.v1',
                    self::failureExample('timeout', 'deadline_exceeded', retryable: true, recorded: true),
                ),
                'malformed_output' => self::fixtureArtifact(
                    'durable-workflow.v2.external-task-result.malformed-output.v1',
                    self::failureExample('malformed_output', 'malformed_output', retryable: false, recorded: false),
                ),
                'cancellation' => self::fixtureArtifact(
                    'durable-workflow.v2.external-task-result.cancellation.v1',
                    self::failureExample('cancellation', 'cancelled', retryable: false, recorded: true, cancelled: true),
                ),
                'handler_crash' => self::fixtureArtifact(
                    'durable-workflow.v2.external-task-result.handler-crash.v1',
                    self::failureExample('handler_crash', 'handler_crash', retryable: true, recorded: true),
                ),
                'decode_failure' => self::fixtureArtifact(
                    'durable-workflow.v2.external-task-result.decode-failure.v1',
                    self::failureExample('decode_failure', 'decode_failure', retryable: false, recorded: true),
                ),
                'unsupported_payload_codec' => self::fixtureArtifact(
                    'durable-workflow.v2.external-task-result.unsupported-payload-codec.v1',
                    self::failureExample(
                        'unsupported_payload',
                        'unsupported_payload_codec',
                        retryable: false,
                        recorded: true,
                    ),
                ),
                'unsupported_payload_reference' => self::fixtureArtifact(
                    'durable-workflow.v2.external-task-result.unsupported-payload-reference.v1',
                    self::failureExample(
                        'unsupported_payload',
                        'unsupported_payload_reference',
                        retryable: false,
                        recorded: true,
                    ),
                ),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $example
     * @return array<string, mixed>
     */
    private static function fixtureArtifact(string $artifact, array $example): array
    {
        return [
            'artifact' => $artifact,
            'media_type' => ExternalTaskResultContract::MEDIA_TYPE,
            'schema' => ExternalTaskResultContract::ENVELOPE_SCHEMA,
            'version' => ExternalTaskResultContract::VERSION,
            'sha256' => hash('sha256', (string) json_encode($example, JSON_UNESCAPED_SLASHES)),
            'example' => $example,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function successExample(): array
    {
        return [
            'schema' => ExternalTaskResultContract::ENVELOPE_SCHEMA,
            'version' => ExternalTaskResultContract::VERSION,
            'outcome' => [
                'status' => 'succeeded',
                'recorded' => true,
            ],
            'task' => self::task(),
            'result' => [
                'payload' => [
                    'codec' => 'avro',
                    'blob' => 'BASE64_AVRO_RESULT',
                ],
                'metadata' => null,
            ],
            'metadata' => self::metadata(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function failureExample(
        string $kind,
        string $classification,
        bool $retryable,
        bool $recorded,
        bool $cancelled = false,
    ): array {
        return [
            'schema' => ExternalTaskResultContract::ENVELOPE_SCHEMA,
            'version' => ExternalTaskResultContract::VERSION,
            'outcome' => [
                'status' => 'failed',
                'retryable' => $retryable,
                'recorded' => $recorded,
            ],
            'task' => self::task(),
            'failure' => [
                'kind' => $kind,
                'classification' => $classification,
                'message' => 'Fixture failure.',
                'type' => 'FixtureFailure',
                'stack_trace' => null,
                'timeout_type' => $classification === 'deadline_exceeded' ? 'deadline_exceeded' : null,
                'cancelled' => $cancelled,
                'details' => null,
            ],
            'metadata' => self::metadata(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function task(): array
    {
        return [
            'id' => 'acttask_01HV7D3G3G61TAH2YB5RK45XJS',
            'kind' => 'activity_task',
            'attempt' => 1,
            'idempotency_key' => 'attempt_01HV7D3KJ1C8WQNNY8MVM8J40X',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function metadata(): array
    {
        return [
            'handler' => 'billing.charge-card',
            'carrier' => 'process-carrier',
            'duration_ms' => 10,
        ];
    }
}
