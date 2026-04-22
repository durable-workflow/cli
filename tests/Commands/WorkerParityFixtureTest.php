<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\WorkerCommand\DeregisterCommand;
use DurableWorkflow\Cli\Commands\WorkerCommand\DescribeCommand;
use DurableWorkflow\Cli\Commands\WorkerCommand\ListCommand;
use DurableWorkflow\Cli\Commands\WorkerCommand\RegisterCommand;
use DurableWorkflow\Cli\Support\ServerClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class WorkerParityFixtureTest extends TestCase
{
    public function test_worker_register_matches_polyglot_request_fixture(): void
    {
        $fixture = self::fixture('worker-register-parity.json', 'worker.register');
        $client = new WorkerParityClient($fixture['response_body']);
        $command = new RegisterCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute($fixture['cli']['argv']));

        self::assertSame($fixture['request']['method'], $client->lastMethod);
        self::assertSame($fixture['request']['path'], $client->lastPath);
        self::assertSame($fixture['request']['body'], $client->lastBody);

        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($fixture['response_body'], $decoded);
        self::assertSame($fixture['semantic_body']['worker_id'], $decoded['worker_id'] ?? null);
    }

    public function test_worker_list_matches_polyglot_request_fixture(): void
    {
        $fixture = self::fixture('worker-list-parity.json', 'worker.list');
        $client = new WorkerParityClient($fixture['response_body']);
        $command = new ListCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute($fixture['cli']['argv']));

        self::assertSame($fixture['request']['method'], $client->lastMethod);
        self::assertSame($fixture['request']['path'], $client->lastPath);
        self::assertSame($fixture['request']['query'], $client->lastQuery);

        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($fixture['response_body'], $decoded);
        self::assertSame($fixture['semantic_body']['worker_ids'], array_column($decoded['workers'], 'worker_id'));
    }

    public function test_worker_describe_matches_polyglot_request_fixture(): void
    {
        $fixture = self::fixture('worker-describe-parity.json', 'worker.describe');
        $client = new WorkerParityClient($fixture['response_body']);
        $command = new DescribeCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute($fixture['cli']['argv']));

        self::assertSame($fixture['request']['method'], $client->lastMethod);
        self::assertSame($fixture['request']['path'], $client->lastPath);

        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($fixture['response_body'], $decoded);
        self::assertSame($fixture['semantic_body']['worker_id'], $decoded['worker_id'] ?? null);
        self::assertSame($fixture['semantic_body']['status'], $decoded['status'] ?? null);
    }

    public function test_worker_deregister_matches_polyglot_request_fixture(): void
    {
        $fixture = self::fixture('worker-deregister-parity.json', 'worker.deregister');
        $client = new WorkerParityClient($fixture['response_body']);
        $command = new DeregisterCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute($fixture['cli']['argv']));

        self::assertSame($fixture['request']['method'], $client->lastMethod);
        self::assertSame($fixture['request']['path'], $client->lastPath);

        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($fixture['response_body'], $decoded);
        self::assertSame($fixture['semantic_body']['outcome'], $decoded['outcome'] ?? null);
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

final class WorkerParityClient extends ServerClient
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
