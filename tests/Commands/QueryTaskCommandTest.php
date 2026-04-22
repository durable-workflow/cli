<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\QueryTaskCommand\CompleteCommand;
use DurableWorkflow\Cli\Commands\QueryTaskCommand\FailCommand;
use DurableWorkflow\Cli\Commands\QueryTaskCommand\PollCommand;
use DurableWorkflow\Cli\Support\ServerClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class QueryTaskCommandTest extends TestCase
{
    public function test_poll_command_matches_polyglot_request_fixture(): void
    {
        $fixture = self::fixture('query-task-poll-parity.json', 'query-task.poll');
        $client = new QueryTaskFakeClient($fixture['response_body']);

        $command = new PollCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute($fixture['cli']['argv']));

        self::assertSame($fixture['request']['method'], $client->lastMethod);
        self::assertSame($fixture['request']['path'], $client->lastPostPath);
        self::assertSame($fixture['request']['body'], $client->lastPostBody);

        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($fixture['response_body'], $decoded);
        self::assertSame($fixture['semantic_body']['query_task_id'], $decoded['task']['query_task_id'] ?? null);
    }

    public function test_complete_command_matches_polyglot_request_fixture(): void
    {
        $fixture = self::fixture('query-task-complete-parity.json', 'query-task.complete');
        $client = new QueryTaskFakeClient($fixture['response_body']);

        $command = new CompleteCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute($fixture['cli']['argv']));

        self::assertSame($fixture['request']['method'], $client->lastMethod);
        self::assertSame($fixture['request']['path'], $client->lastPostPath);
        self::assertSame($fixture['request']['body'], $client->lastPostBody);

        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($fixture['response_body'], $decoded);
        self::assertSame($fixture['semantic_body']['outcome'], $decoded['outcome'] ?? null);
    }

    public function test_fail_command_matches_polyglot_request_fixture(): void
    {
        $fixture = self::fixture('query-task-fail-parity.json', 'query-task.fail');
        $client = new QueryTaskFakeClient($fixture['response_body']);

        $command = new FailCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute($fixture['cli']['argv']));

        self::assertSame($fixture['request']['method'], $client->lastMethod);
        self::assertSame($fixture['request']['path'], $client->lastPostPath);
        self::assertSame($fixture['request']['body'], $client->lastPostBody);

        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($fixture['response_body'], $decoded);
        self::assertSame($fixture['semantic_body']['reason'], $client->lastPostBody['failure']['reason'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private static function fixture(string $file, string $operation): array
    {
        $contents = file_get_contents(__DIR__.'/../fixtures/control-plane/'.$file);
        self::assertIsString($contents);

        $fixture = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($fixture);
        self::assertSame('durable-workflow.polyglot.control-plane-request-fixture', $fixture['schema'] ?? null);
        self::assertSame($operation, $fixture['operation'] ?? null);

        return $fixture;
    }
}

class QueryTaskFakeClient extends ServerClient
{
    /** @var array<string, mixed> */
    public array $lastPostBody = [];

    public ?string $lastMethod = null;

    public string $lastPostPath = '';

    /** @param array<string, mixed> $payload */
    public function __construct(
        private readonly array $payload,
    ) {}

    public function post(string $path, array $body = []): array
    {
        $this->lastMethod = 'POST';
        $this->lastPostPath = $path;
        $this->lastPostBody = $body;

        return $this->payload;
    }
}
