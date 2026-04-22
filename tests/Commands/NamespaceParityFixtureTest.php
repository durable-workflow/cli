<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\NamespaceCommand\CreateCommand;
use DurableWorkflow\Cli\Commands\NamespaceCommand\DescribeCommand;
use DurableWorkflow\Cli\Commands\NamespaceCommand\ListCommand;
use DurableWorkflow\Cli\Commands\NamespaceCommand\UpdateCommand;
use DurableWorkflow\Cli\Support\ServerClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class NamespaceParityFixtureTest extends TestCase
{
    public function test_namespace_list_matches_polyglot_request_fixture(): void
    {
        $fixture = self::fixture('namespace-list-parity.json', 'namespace.list');
        $client = new NamespaceParityClient($fixture['response_body']);
        $command = new ListCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute($fixture['cli']['argv']));

        self::assertSame($fixture['request']['method'], $client->lastMethod);
        self::assertSame($fixture['request']['path'], $client->lastPath);
        self::assertSame([], $client->lastQuery);

        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($fixture['response_body'], $decoded);
        self::assertSame($fixture['semantic_body']['namespace_names'], array_column($decoded['namespaces'], 'name'));
    }

    public function test_namespace_describe_matches_polyglot_request_fixture(): void
    {
        $fixture = self::fixture('namespace-describe-parity.json', 'namespace.describe');
        $client = new NamespaceParityClient($fixture['response_body']);
        $command = new DescribeCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute($fixture['cli']['argv']));

        self::assertSame($fixture['request']['method'], $client->lastMethod);
        self::assertSame($fixture['request']['path'], $client->lastPath);

        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($fixture['response_body'], $decoded);
        self::assertSame($fixture['semantic_body']['name'], $decoded['name'] ?? null);
        self::assertSame($fixture['semantic_body']['retention_days'], $decoded['retention_days'] ?? null);
    }

    public function test_namespace_create_matches_polyglot_request_fixture(): void
    {
        $fixture = self::fixture('namespace-create-parity.json', 'namespace.create');
        $client = new NamespaceParityClient($fixture['response_body']);
        $command = new CreateCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute($fixture['cli']['argv']));

        self::assertSame($fixture['request']['method'], $client->lastMethod);
        self::assertSame($fixture['request']['path'], $client->lastPath);
        self::assertSame($fixture['request']['body'], $client->lastBody);

        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($fixture['response_body'], $decoded);
        self::assertSame($fixture['semantic_body']['name'], $decoded['name'] ?? null);
    }

    public function test_namespace_update_matches_polyglot_request_fixture(): void
    {
        $fixture = self::fixture('namespace-update-parity.json', 'namespace.update');
        $client = new NamespaceParityClient($fixture['response_body']);
        $command = new UpdateCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute($fixture['cli']['argv']));

        self::assertSame($fixture['request']['method'], $client->lastMethod);
        self::assertSame($fixture['request']['path'], $client->lastPath);
        self::assertSame($fixture['request']['body'], $client->lastBody);

        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($fixture['response_body'], $decoded);
        self::assertSame($fixture['semantic_body']['description'], $decoded['description'] ?? null);
        self::assertSame($fixture['semantic_body']['retention_days'], $decoded['retention_days'] ?? null);
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

final class NamespaceParityClient extends ServerClient
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

    public function put(string $path, array $body = []): array
    {
        $this->lastMethod = 'PUT';
        $this->lastPath = $path;
        $this->lastBody = $body;

        return $this->response;
    }
}
