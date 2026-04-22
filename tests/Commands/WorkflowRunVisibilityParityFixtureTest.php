<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\WorkflowCommand\ListRunsCommand;
use DurableWorkflow\Cli\Commands\WorkflowCommand\ShowRunCommand;
use DurableWorkflow\Cli\Support\ServerClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class WorkflowRunVisibilityParityFixtureTest extends TestCase
{
    public function test_workflow_list_runs_matches_polyglot_request_fixture(): void
    {
        $fixture = self::fixture('workflow-list-runs-parity.json', 'workflow.list_runs');
        $client = new WorkflowRunVisibilityParityClient($fixture['response_body']);
        $command = new ListRunsCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute($fixture['cli']['argv']));

        self::assertSame($fixture['request']['method'], $client->lastMethod);
        self::assertSame($fixture['request']['path'], $client->lastPath);
        self::assertSame([], $client->lastQuery);

        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($fixture['response_body'], $decoded);

        $semantic = $fixture['semantic_body'];
        self::assertSame($semantic['workflow_id'], $decoded['workflow_id'] ?? null);
        self::assertSame($semantic['run_count'], $decoded['run_count'] ?? null);
        self::assertSame($semantic['run_ids'], array_column($decoded['runs'] ?? [], 'run_id'));
    }

    public function test_workflow_show_run_matches_polyglot_request_fixture(): void
    {
        $fixture = self::fixture('workflow-show-run-parity.json', 'workflow.show_run');
        $client = new WorkflowRunVisibilityParityClient($fixture['response_body']);
        $command = new ShowRunCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute($fixture['cli']['argv']));

        self::assertSame($fixture['request']['method'], $client->lastMethod);
        self::assertSame($fixture['request']['path'], $client->lastPath);
        self::assertSame([], $client->lastQuery);

        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($fixture['response_body'], $decoded);

        $semantic = $fixture['semantic_body'];
        self::assertSame($semantic['workflow_id'], $decoded['workflow_id'] ?? null);
        self::assertSame($semantic['run_id'], $decoded['run_id'] ?? null);
        self::assertSame('active', $decoded['status_bucket'] ?? null);
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

final class WorkflowRunVisibilityParityClient extends ServerClient
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
