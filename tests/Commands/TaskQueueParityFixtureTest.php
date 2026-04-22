<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\TaskQueueCommand\BuildIdsCommand;
use DurableWorkflow\Cli\Commands\TaskQueueCommand\DescribeCommand;
use DurableWorkflow\Cli\Commands\TaskQueueCommand\DrainCommand;
use DurableWorkflow\Cli\Commands\TaskQueueCommand\ListCommand;
use DurableWorkflow\Cli\Commands\TaskQueueCommand\ResumeCommand;
use DurableWorkflow\Cli\Support\ServerClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class TaskQueueParityFixtureTest extends TestCase
{
    public function test_task_queue_list_matches_polyglot_request_fixture(): void
    {
        $fixture = self::fixture('task-queue-list-parity.json', 'task_queue.list');
        $client = new TaskQueueParityClient($fixture['response_body']);
        $command = new ListCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute($fixture['cli']['argv']));

        self::assertSame($fixture['request']['method'], $client->lastMethod);
        self::assertSame($fixture['request']['path'], $client->lastPath);
        self::assertSame([], $client->lastQuery);

        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($fixture['response_body'], $decoded);

        $semantic = $fixture['semantic_body'];
        self::assertSame($semantic['namespace'], $decoded['namespace'] ?? null);
        self::assertSame($semantic['task_queue_names'], array_column($decoded['task_queues'] ?? [], 'name'));

        $statuses = [];
        foreach ($decoded['task_queues'] ?? [] as $queue) {
            $statuses[$queue['name']] = $queue['admission']['workflow_tasks']['status'] ?? null;
        }

        self::assertSame($semantic['workflow_admission_statuses'], $statuses);
    }

    public function test_task_queue_describe_matches_polyglot_request_fixture(): void
    {
        $fixture = self::fixture('task-queue-describe-parity.json', 'task_queue.describe');
        $client = new TaskQueueParityClient($fixture['response_body']);
        $command = new DescribeCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute($fixture['cli']['argv']));

        self::assertSame($fixture['request']['method'], $client->lastMethod);
        self::assertSame($fixture['request']['path'], $client->lastPath);
        self::assertSame([], $client->lastQuery);

        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($fixture['response_body'], $decoded);

        $semantic = $fixture['semantic_body'];
        self::assertSame($semantic['namespace'], $decoded['namespace'] ?? null);
        self::assertSame($semantic['task_queue'], $decoded['name'] ?? null);
        self::assertSame($semantic['workflow_admission_status'], $decoded['admission']['workflow_tasks']['status'] ?? null);
        self::assertSame($semantic['activity_admission_status'], $decoded['admission']['activity_tasks']['status'] ?? null);
        self::assertSame($semantic['query_admission_status'], $decoded['admission']['query_tasks']['status'] ?? null);
        self::assertSame($semantic['active_pollers'], $decoded['stats']['pollers']['active_count'] ?? null);
        self::assertSame($semantic['current_lease_ids'], array_column($decoded['current_leases'] ?? [], 'task_id'));
    }

    public function test_task_queue_build_ids_matches_polyglot_request_fixture(): void
    {
        $fixture = self::fixture('task-queue-build-ids-parity.json', 'task_queue.build_ids');
        $client = new TaskQueueParityClient($fixture['response_body']);
        $command = new BuildIdsCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute($fixture['cli']['argv']));

        self::assertSame($fixture['request']['method'], $client->lastMethod);
        self::assertSame($fixture['request']['path'], $client->lastPath);
        self::assertSame([], $client->lastQuery);

        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($fixture['response_body'], $decoded);

        $semantic = $fixture['semantic_body'];
        self::assertSame($semantic['namespace'], $decoded['namespace'] ?? null);
        self::assertSame($semantic['task_queue'], $decoded['task_queue'] ?? null);

        $actualBuildIds = array_map(
            static fn (array $entry): mixed => $entry['build_id'],
            $decoded['build_ids'] ?? [],
        );
        self::assertSame($semantic['build_ids'], $actualBuildIds);

        $expectedStatuses = $semantic['rollout_statuses'];
        foreach ($decoded['build_ids'] ?? [] as $entry) {
            $key = $entry['build_id'] ?? 'unversioned';
            self::assertArrayHasKey($key, $expectedStatuses);
            self::assertSame($expectedStatuses[$key], $entry['rollout_status']);
        }
    }

    public function test_task_queue_drain_matches_polyglot_request_fixture(): void
    {
        $fixture = self::fixture(
            'task-queue-build-id-drain-parity.json',
            'task_queue.build_id.drain',
        );
        $client = new TaskQueueParityClient($fixture['response_body']);
        $command = new DrainCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute($fixture['cli']['argv']));

        self::assertSame($fixture['request']['method'], $client->lastMethod);
        self::assertSame($fixture['request']['path'], $client->lastPath);
        self::assertSame($fixture['request']['body'], $client->lastBody);

        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($fixture['response_body'], $decoded);

        $semantic = $fixture['semantic_body'];
        self::assertSame($semantic['namespace'], $decoded['namespace'] ?? null);
        self::assertSame($semantic['task_queue'], $decoded['task_queue'] ?? null);
        self::assertSame($semantic['build_id'], $decoded['build_id'] ?? null);
        self::assertSame($semantic['drain_intent'], $decoded['drain_intent'] ?? null);
        self::assertSame($semantic['drained_at'], $decoded['drained_at'] ?? null);
    }

    public function test_task_queue_resume_matches_polyglot_request_fixture(): void
    {
        $fixture = self::fixture(
            'task-queue-build-id-resume-parity.json',
            'task_queue.build_id.resume',
        );
        $client = new TaskQueueParityClient($fixture['response_body']);
        $command = new ResumeCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute($fixture['cli']['argv']));

        self::assertSame($fixture['request']['method'], $client->lastMethod);
        self::assertSame($fixture['request']['path'], $client->lastPath);
        self::assertSame($fixture['request']['body'], $client->lastBody);

        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($fixture['response_body'], $decoded);

        $semantic = $fixture['semantic_body'];
        self::assertSame($semantic['namespace'], $decoded['namespace'] ?? null);
        self::assertSame($semantic['task_queue'], $decoded['task_queue'] ?? null);
        self::assertSame($semantic['build_id'], $decoded['build_id'] ?? null);
        self::assertSame($semantic['drain_intent'], $decoded['drain_intent'] ?? null);
        self::assertSame($semantic['drained_at'], $decoded['drained_at'] ?? null);
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

final class TaskQueueParityClient extends ServerClient
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
