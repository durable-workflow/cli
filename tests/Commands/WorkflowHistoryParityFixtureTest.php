<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\WorkflowCommand\HistoryCommand;
use DurableWorkflow\Cli\Commands\WorkflowCommand\HistoryExportCommand;
use DurableWorkflow\Cli\Support\ServerClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class WorkflowHistoryParityFixtureTest extends TestCase
{
    public function test_workflow_history_matches_polyglot_request_fixture(): void
    {
        $fixture = self::fixture();
        $client = new WorkflowHistoryParityClient($fixture['response_body']);
        $command = new HistoryCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute($fixture['cli']['argv']));

        self::assertSame($fixture['request']['method'], $client->lastMethod);
        self::assertSame($fixture['request']['path'], $client->lastPath);
        self::assertSame($fixture['cli']['expected_query'], $client->lastQuery);

        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($fixture['response_body'], $decoded);

        $semantic = $fixture['semantic_body'];
        self::assertSame($semantic['workflow_id'], $decoded['workflow_id'] ?? null, 'history workflow id drifted from fixture semantics.');
        self::assertSame($semantic['run_id'], $decoded['run_id'] ?? null, 'history run id drifted from fixture semantics.');
    }

    public function test_workflow_history_export_matches_polyglot_request_fixture(): void
    {
        $fixture = self::fixture('workflow-history-export-parity.json', 'workflow.history_export');
        $client = new WorkflowHistoryParityClient($fixture['response_body']);
        $command = new HistoryExportCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute($fixture['cli']['argv']));

        self::assertSame($fixture['request']['method'], $client->lastMethod);
        self::assertSame($fixture['request']['path'], $client->lastPath);
        self::assertSame([], $client->lastQuery);

        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($fixture['response_body'], $decoded);

        $semantic = $fixture['semantic_body'];
        self::assertSame($semantic['schema'], $decoded['schema'] ?? null, 'history export schema drifted from fixture semantics.');
        self::assertSame($semantic['workflow_id'], $decoded['workflow']['instance_id'] ?? null, 'history export workflow id drifted from fixture semantics.');
        self::assertSame($semantic['run_id'], $decoded['workflow']['run_id'] ?? null, 'history export run id drifted from fixture semantics.');
        self::assertCount($semantic['event_count'], $decoded['events'] ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    private static function fixture(
        string $file = 'workflow-history-parity.json',
        string $operation = 'workflow.history',
    ): array
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

final class WorkflowHistoryParityClient extends ServerClient
{
    public ?string $lastMethod = null;

    public ?string $lastPath = null;

    /**
     * @var array<string, mixed>
     */
    public array $lastQuery = [];

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
}
