<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\ScheduleCommand\DescribeCommand;
use DurableWorkflow\Cli\Commands\ScheduleCommand\DeleteCommand;
use DurableWorkflow\Cli\Commands\ScheduleCommand\ListCommand;
use DurableWorkflow\Cli\Commands\ScheduleCommand\PauseCommand;
use DurableWorkflow\Cli\Commands\ScheduleCommand\ResumeCommand;
use DurableWorkflow\Cli\Support\ServerClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ScheduleParityFixtureTest extends TestCase
{
    public function test_schedule_list_matches_polyglot_request_fixture(): void
    {
        $fixture = self::fixture('schedule-list-parity.json', 'schedule.list');
        $client = new ScheduleParityClient($fixture['response_body']);
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
        self::assertSame($semantic['schedule_ids'], array_column($decoded['schedules'] ?? [], 'schedule_id'));
        self::assertSame($semantic['workflow_types'], array_column($decoded['schedules'] ?? [], 'workflow_type'));
        self::assertSame($semantic['next_page_token'], $decoded['next_page_token'] ?? null);

        $statuses = [];
        foreach ($decoded['schedules'] ?? [] as $schedule) {
            $statuses[$schedule['schedule_id']] = $schedule['status'] ?? null;
        }

        self::assertSame($semantic['statuses'], $statuses);
    }

    public function test_schedule_describe_matches_polyglot_request_fixture(): void
    {
        $fixture = self::fixture('schedule-describe-parity.json', 'schedule.describe');
        $client = new ScheduleParityClient($fixture['response_body']);
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
        self::assertSame($semantic['schedule_id'], $decoded['schedule_id'] ?? null);
        self::assertSame($semantic['status'], $decoded['status'] ?? null);
        self::assertSame($semantic['workflow_type'], $decoded['action']['workflow_type'] ?? null);
        self::assertSame($semantic['task_queue'], $decoded['action']['task_queue'] ?? null);
        self::assertSame($semantic['overlap_policy'], $decoded['overlap_policy'] ?? null);
        self::assertSame($semantic['fires_count'], $decoded['fires_count'] ?? null);
        self::assertSame($semantic['remaining_actions'], $decoded['remaining_actions'] ?? null);
    }

    public function test_schedule_pause_matches_polyglot_request_fixture(): void
    {
        $fixture = self::fixture('schedule-pause-parity.json', 'schedule.pause');
        $client = new ScheduleParityClient($fixture['response_body']);
        $command = new PauseCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute($fixture['cli']['argv']));

        self::assertSame($fixture['request']['method'], $client->lastMethod);
        self::assertSame($fixture['request']['path'], $client->lastPath);
        self::assertSame($fixture['request']['body'], $client->lastBody);

        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($fixture['response_body'], $decoded);

        $semantic = $fixture['semantic_body'];
        self::assertSame($semantic['schedule_id'], $decoded['schedule_id'] ?? null);
        self::assertSame($semantic['outcome'], $decoded['outcome'] ?? null);
    }

    public function test_schedule_resume_matches_polyglot_request_fixture(): void
    {
        $fixture = self::fixture('schedule-resume-parity.json', 'schedule.resume');
        $client = new ScheduleParityClient($fixture['response_body']);
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
        self::assertSame($semantic['schedule_id'], $decoded['schedule_id'] ?? null);
        self::assertSame($semantic['outcome'], $decoded['outcome'] ?? null);
    }

    public function test_schedule_delete_matches_polyglot_request_fixture(): void
    {
        $fixture = self::fixture('schedule-delete-parity.json', 'schedule.delete');
        $client = new ScheduleParityClient($fixture['response_body']);
        $command = new DeleteCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute($fixture['cli']['argv']));

        self::assertSame($fixture['request']['method'], $client->lastMethod);
        self::assertSame($fixture['request']['path'], $client->lastPath);
        self::assertSame([], $client->lastBody);

        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($fixture['response_body'], $decoded);

        $semantic = $fixture['semantic_body'];
        self::assertSame($semantic['schedule_id'], $decoded['schedule_id'] ?? null);
        self::assertSame($semantic['outcome'], $decoded['outcome'] ?? null);
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

final class ScheduleParityClient extends ServerClient
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

    public function delete(string $path): array
    {
        $this->lastMethod = 'DELETE';
        $this->lastPath = $path;
        $this->lastBody = [];

        return $this->response;
    }
}
