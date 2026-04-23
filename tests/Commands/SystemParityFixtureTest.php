<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\SystemCommand\ActivityTimeoutPassCommand;
use DurableWorkflow\Cli\Commands\SystemCommand\ActivityTimeoutStatusCommand;
use DurableWorkflow\Cli\Commands\SystemCommand\RepairPassCommand;
use DurableWorkflow\Cli\Commands\SystemCommand\RepairStatusCommand;
use DurableWorkflow\Cli\Commands\SystemCommand\RetentionPassCommand;
use DurableWorkflow\Cli\Commands\SystemCommand\RetentionStatusCommand;
use DurableWorkflow\Cli\Support\ServerClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SystemParityFixtureTest extends TestCase
{
    public function test_system_repair_status_matches_polyglot_request_fixture(): void
    {
        $fixture = self::fixture('system-repair-status-parity.json', 'system.repair.status');
        $client = new SystemParityClient($fixture['response_body']);
        $command = new RepairStatusCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute($fixture['cli']['argv']));

        self::assertSame($fixture['request']['method'], $client->lastMethod);
        self::assertSame($fixture['request']['path'], $client->lastPath);
        self::assertSame([], $client->lastQuery);

        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($fixture['response_body'], $decoded);

        $semantic = $fixture['semantic_body'];
        $candidates = $decoded['candidates'];
        self::assertSame($semantic['total_candidates'], $candidates['total_candidates']);
        self::assertSame($semantic['existing_task_candidates'], $candidates['existing_task_candidates']);
        self::assertSame($semantic['missing_task_candidates'], $candidates['missing_task_candidates']);
        self::assertSame($semantic['scan_pressure'], $candidates['scan_pressure']);
        self::assertSame($semantic['scan_limit'], $decoded['policy']['scan_limit']);
        self::assertSame($semantic['scan_strategy'], $decoded['policy']['scan_strategy']);
        self::assertSame(
            $semantic['scope_keys'],
            array_column($candidates['scopes'], 'scope_key'),
        );
    }

    public function test_system_repair_pass_matches_polyglot_request_fixture(): void
    {
        $fixture = self::fixture('system-repair-pass-parity.json', 'system.repair.pass');
        $client = new SystemParityClient($fixture['response_body']);
        $command = new RepairPassCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute($fixture['cli']['argv']));

        self::assertSame($fixture['request']['method'], $client->lastMethod);
        self::assertSame($fixture['request']['path'], $client->lastPath);
        self::assertSame($fixture['request']['body'], $client->lastBody);

        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($fixture['response_body'], $decoded);

        $semantic = $fixture['semantic_body'];
        self::assertSame($semantic['repaired_existing_tasks'], $decoded['repaired_existing_tasks']);
        self::assertSame($semantic['repaired_missing_tasks'], $decoded['repaired_missing_tasks']);
        self::assertSame($semantic['dispatched_tasks'], $decoded['dispatched_tasks']);
        self::assertSame($semantic['selected_existing_task_candidates'], $decoded['selected_existing_task_candidates']);
        self::assertSame($semantic['selected_missing_task_candidates'], $decoded['selected_missing_task_candidates']);
        self::assertSame($semantic['existing_task_failures'], $decoded['existing_task_failures']);
        self::assertSame($semantic['missing_run_failures'], $decoded['missing_run_failures']);
    }

    public function test_system_retention_status_matches_polyglot_request_fixture(): void
    {
        $fixture = self::fixture('system-retention-status-parity.json', 'system.retention.status');
        $client = new SystemParityClient($fixture['response_body']);
        $command = new RetentionStatusCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute($fixture['cli']['argv']));

        self::assertSame($fixture['request']['method'], $client->lastMethod);
        self::assertSame($fixture['request']['path'], $client->lastPath);
        self::assertSame([], $client->lastQuery);

        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($fixture['response_body'], $decoded);

        $semantic = $fixture['semantic_body'];
        self::assertSame($semantic['namespace'], $decoded['namespace']);
        self::assertSame($semantic['retention_days'], $decoded['retention_days']);
        self::assertSame($semantic['cutoff'], $decoded['cutoff']);
        self::assertSame($semantic['expired_run_count'], $decoded['expired_run_count']);
        self::assertSame($semantic['expired_run_ids'], $decoded['expired_run_ids']);
        self::assertSame($semantic['scan_limit'], $decoded['scan_limit']);
        self::assertSame($semantic['scan_pressure'], $decoded['scan_pressure']);
    }

    public function test_system_retention_pass_matches_polyglot_request_fixture(): void
    {
        $fixture = self::fixture('system-retention-pass-parity.json', 'system.retention.pass');
        $client = new SystemParityClient($fixture['response_body']);
        $command = new RetentionPassCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute($fixture['cli']['argv']));

        self::assertSame($fixture['request']['method'], $client->lastMethod);
        self::assertSame($fixture['request']['path'], $client->lastPath);
        self::assertSame($fixture['request']['body'], $client->lastBody);

        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($fixture['response_body'], $decoded);

        $semantic = $fixture['semantic_body'];
        self::assertSame($semantic['processed'], $decoded['processed']);
        self::assertSame($semantic['pruned'], $decoded['pruned']);
        self::assertSame($semantic['skipped'], $decoded['skipped']);
        self::assertSame($semantic['failed'], $decoded['failed']);

        $pruned = array_values(array_filter(
            $decoded['results'],
            static fn (array $r): bool => ($r['outcome'] ?? null) === 'pruned',
        ));
        $skipped = array_values(array_filter(
            $decoded['results'],
            static fn (array $r): bool => ($r['outcome'] ?? null) === 'skipped',
        ));
        self::assertSame($semantic['pruned_run_ids'], array_column($pruned, 'run_id'));
        self::assertSame($semantic['skipped_run_ids'], array_column($skipped, 'run_id'));
    }

    public function test_system_activity_timeout_status_matches_polyglot_request_fixture(): void
    {
        $fixture = self::fixture(
            'system-activity-timeout-status-parity.json',
            'system.activity_timeout.status',
        );
        $client = new SystemParityClient($fixture['response_body']);
        $command = new ActivityTimeoutStatusCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute($fixture['cli']['argv']));

        self::assertSame($fixture['request']['method'], $client->lastMethod);
        self::assertSame($fixture['request']['path'], $client->lastPath);
        self::assertSame([], $client->lastQuery);

        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($fixture['response_body'], $decoded);

        $semantic = $fixture['semantic_body'];
        self::assertSame($semantic['expired_count'], $decoded['expired_count']);
        self::assertSame($semantic['expired_execution_ids'], $decoded['expired_execution_ids']);
        self::assertSame($semantic['scan_limit'], $decoded['scan_limit']);
        self::assertSame($semantic['scan_pressure'], $decoded['scan_pressure']);
    }

    public function test_system_activity_timeout_pass_matches_polyglot_request_fixture(): void
    {
        $fixture = self::fixture(
            'system-activity-timeout-pass-parity.json',
            'system.activity_timeout.pass',
        );
        $client = new SystemParityClient($fixture['response_body']);
        $command = new ActivityTimeoutPassCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute($fixture['cli']['argv']));

        self::assertSame($fixture['request']['method'], $client->lastMethod);
        self::assertSame($fixture['request']['path'], $client->lastPath);
        self::assertSame($fixture['request']['body'], $client->lastBody);

        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($fixture['response_body'], $decoded);

        $semantic = $fixture['semantic_body'];
        self::assertSame($semantic['processed'], $decoded['processed']);
        self::assertSame($semantic['enforced'], $decoded['enforced']);
        self::assertSame($semantic['skipped'], $decoded['skipped']);
        self::assertSame($semantic['failed'], $decoded['failed']);

        $enforced = array_values(array_filter(
            $decoded['results'],
            static fn (array $r): bool => ($r['outcome'] ?? null) === 'enforced',
        ));
        $skipped = array_values(array_filter(
            $decoded['results'],
            static fn (array $r): bool => ($r['outcome'] ?? null) === 'skipped',
        ));
        self::assertSame($semantic['enforced_execution_ids'], array_column($enforced, 'execution_id'));
        self::assertSame($semantic['skipped_execution_ids'], array_column($skipped, 'execution_id'));
    }

    /**
     * @return array<string, mixed>
     */
    private static function fixture(string $file, string $operation): array
    {
        $path = __DIR__.'/../fixtures/control-plane/'.$file;
        $contents = file_get_contents($path);
        self::assertIsString($contents);

        $fixture = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($fixture);
        self::assertSame('durable-workflow.polyglot.control-plane-request-fixture', $fixture['schema'] ?? null);
        self::assertSame($operation, $fixture['operation'] ?? null);

        return $fixture;
    }
}

final class SystemParityClient extends ServerClient
{
    public ?string $lastMethod = null;

    public ?string $lastPath = null;

    /**
     * @var array<string, mixed>
     */
    public array $lastQuery = [];

    /**
     * @var array<string, mixed>
     */
    public array $lastBody = [];

    /**
     * @param  array<string, mixed>  $response
     */
    public function __construct(private readonly array $response)
    {
        parent::__construct('http://localhost');
    }

    public function get(string $path, array $query = []): array
    {
        $this->lastMethod = 'GET';
        $this->lastPath = $path;
        $this->lastQuery = $query;

        return $this->response;
    }

    public function post(string $path, array $body = []): array
    {
        $this->lastMethod = 'POST';
        $this->lastPath = $path;
        $this->lastBody = $body;

        return $this->response;
    }
}
