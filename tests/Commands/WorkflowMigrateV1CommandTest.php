<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\WorkflowCommand\MigrateV1Command;
use DurableWorkflow\Cli\Support\ServerClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class WorkflowMigrateV1CommandTest extends TestCase
{
    public function test_it_posts_a_waterline_projection_and_outputs_the_typed_report(): void
    {
        $path = $this->projectionFile();
        $client = new MigrateV1FakeClient($this->report());
        $command = new MigrateV1Command();
        $command->setServerClient($client);

        try {
            $tester = new CommandTester($command);
            self::assertSame(Command::SUCCESS, $tester->execute([
                'projection' => $path,
                '--source-id' => 'legacy-prod',
                '--namespace' => 'migration',
                '--json' => true,
            ]));

            $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('durable-workflow.v2.waterline-v1-projection.report', $decoded['schema']);
            self::assertSame('projected', $decoded['status']);
            self::assertSame('migration', $decoded['namespace']);
            self::assertSame('/workflows/import/waterline-v1', $client->lastPostPath);
            self::assertSame('legacy-prod', $client->lastPostBody['source_id'] ?? null);
            self::assertSame('v1:42', $client->lastPostBody['workflow']['operator_id'] ?? null);
            self::assertFalse($client->lastPostBody['dry_run'] ?? true);
        } finally {
            @unlink($path);
        }
    }

    public function test_dry_run_is_forwarded_without_changing_the_source_document(): void
    {
        $path = $this->projectionFile();
        $report = $this->report();
        $report['status'] = 'dry_run';
        $client = new MigrateV1FakeClient($report);
        $command = new MigrateV1Command();
        $command->setServerClient($client);

        try {
            $tester = new CommandTester($command);
            self::assertSame(Command::SUCCESS, $tester->execute([
                'projection' => $path,
                '--source-id' => 'legacy-prod',
                '--dry-run' => true,
            ]));

            self::assertTrue($client->lastPostBody['dry_run'] ?? false);
            self::assertStringContainsString('Status: dry_run', $tester->getDisplay());
            self::assertStringContainsString('Waterline ID: v1:42', $tester->getDisplay());
            self::assertStringContainsString('v1_history_not_replayable_as_v2', $tester->getDisplay());
        } finally {
            @unlink($path);
        }
    }

    public function test_it_rejects_invalid_projection_json_before_calling_the_server(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'dw-v1-invalid-');
        self::assertIsString($path);
        file_put_contents($path, '{not-json');

        $client = new MigrateV1FakeClient($this->report());
        $command = new MigrateV1Command();
        $command->setServerClient($client);

        try {
            $tester = new CommandTester($command);
            self::assertSame(2, $tester->execute([
                'projection' => $path,
                '--source-id' => 'legacy-prod',
                '--json' => true,
            ]));

            $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
            self::assertStringContainsString('must be valid JSON', $decoded['error'] ?? '');
            self::assertSame(2, $decoded['exit_code'] ?? null);
            self::assertSame('', $client->lastPostPath);
        } finally {
            @unlink($path);
        }
    }

    private function projectionFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'dw-v1-projection-');
        self::assertIsString($path);
        file_put_contents($path, json_encode([
            'id' => 'v1:42',
            'legacy_id' => 42,
            'operator_id' => 'v1:42',
            'engine_source' => 'v1',
            'class' => 'App\\Workflows\\LegacyOrderWorkflow',
            'status' => 'completed',
            'logs' => [],
            'signals' => [],
            'exceptions' => [],
        ], JSON_THROW_ON_ERROR));

        return $path;
    }

    /** @return array<string, mixed> */
    private function report(): array
    {
        return [
            'schema' => 'durable-workflow.v2.waterline-v1-projection.report',
            'schema_version' => 1,
            'status' => 'projected',
            'projection_only' => true,
            'identity' => [
                'waterline' => [
                    'source_id' => 'legacy-prod',
                    'qualified_workflow_id' => 'v1:42',
                    'legacy_workflow_id' => '42',
                ],
                'standalone' => [
                    'namespace' => 'migration',
                    'workflow_id' => 'v1:legacy-prod:abc123',
                    'run_id' => 'def456',
                ],
                'relationship' => 'deterministic_source_qualified_projection',
                'collision_policy' => 'reject_without_overwrite',
            ],
            'unsupported_fields' => [[
                'field' => 'runtime.replay',
                'reason' => 'v1_history_not_replayable_as_v2',
                'remediation' => 'Retain v1 storage.',
            ]],
        ];
    }
}

final class MigrateV1FakeClient extends ServerClient
{
    public string $lastPostPath = '';

    /** @var array<string, mixed> */
    public array $lastPostBody = [];

    /** @param array<string, mixed> $payload */
    public function __construct(private readonly array $payload) {}

    public function post(string $path, array $body = []): array
    {
        $this->lastPostPath = $path;
        $this->lastPostBody = $body;

        return $this->payload;
    }
}
