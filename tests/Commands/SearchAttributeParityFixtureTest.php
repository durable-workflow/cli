<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\SearchAttributeCommand\CreateCommand;
use DurableWorkflow\Cli\Commands\SearchAttributeCommand\DeleteCommand;
use DurableWorkflow\Cli\Commands\SearchAttributeCommand\ListCommand;
use DurableWorkflow\Cli\Support\ServerClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SearchAttributeParityFixtureTest extends TestCase
{
    public function test_search_attribute_list_matches_polyglot_request_fixture(): void
    {
        $fixture = self::fixture('search-attribute-list-parity.json', 'search_attribute.list');
        $client = new SearchAttributeParityClient($fixture['response_body']);
        $command = new ListCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute($fixture['cli']['argv']));

        self::assertSame($fixture['request']['method'], $client->lastMethod);
        self::assertSame($fixture['request']['path'], $client->lastPath);
        self::assertSame([], $client->lastQuery);

        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($fixture['response_body'], $decoded);
        self::assertSame($fixture['semantic_body']['system_attributes'], $decoded['system_attributes'] ?? null);
        self::assertSame($fixture['semantic_body']['custom_attributes'], $decoded['custom_attributes'] ?? null);
    }

    public function test_search_attribute_create_matches_polyglot_request_fixture(): void
    {
        $fixture = self::fixture('search-attribute-create-parity.json', 'search_attribute.create');
        $client = new SearchAttributeParityClient($fixture['response_body']);
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
        self::assertSame($fixture['semantic_body']['type'], $decoded['type'] ?? null);
    }

    public function test_search_attribute_delete_matches_polyglot_request_fixture(): void
    {
        $fixture = self::fixture('search-attribute-delete-parity.json', 'search_attribute.delete');
        $client = new SearchAttributeParityClient($fixture['response_body']);
        $command = new DeleteCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute($fixture['cli']['argv']));

        self::assertSame($fixture['request']['method'], $client->lastMethod);
        self::assertSame($fixture['request']['path'], $client->lastPath);
        self::assertSame([], $client->lastBody);

        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($fixture['response_body'], $decoded);
        self::assertSame($fixture['semantic_body']['name'], $decoded['name'] ?? null);
        self::assertSame($fixture['semantic_body']['outcome'], $decoded['outcome'] ?? null);
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

final class SearchAttributeParityClient extends ServerClient
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
