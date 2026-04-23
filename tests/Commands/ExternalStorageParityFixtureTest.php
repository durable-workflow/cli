<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\NamespaceCommand\SetStorageDriverCommand;
use DurableWorkflow\Cli\Commands\StorageCommand\TestCommand;
use DurableWorkflow\Cli\Support\ServerClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ExternalStorageParityFixtureTest extends TestCase
{
    public function test_namespace_set_storage_driver_matches_polyglot_request_fixture(): void
    {
        $fixture = self::fixture('namespace-set-storage-driver-parity.json', 'namespace.set_storage_driver');
        $client = new ExternalStorageParityClient($fixture['response_body']);
        $command = new SetStorageDriverCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute($fixture['cli']['argv']));

        self::assertSame($fixture['request']['method'], $client->lastMethod);
        self::assertSame($fixture['request']['path'], $client->lastPath);
        self::assertSame($fixture['request']['body'], $client->lastBody);

        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($fixture['response_body'], $decoded);

        $semantic = $fixture['semantic_body'];
        self::assertSame($semantic['namespace'], $decoded['name'] ?? null);
        self::assertSame($semantic['driver'], $decoded['external_payload_storage']['driver'] ?? null);
        self::assertSame($semantic['enabled'], $decoded['external_payload_storage']['enabled'] ?? null);
        self::assertSame($semantic['threshold_bytes'], $decoded['external_payload_storage']['threshold_bytes'] ?? null);
        self::assertSame($semantic['disk'], $decoded['external_payload_storage']['config']['disk'] ?? null);
        self::assertSame($semantic['bucket'], $decoded['external_payload_storage']['config']['bucket'] ?? null);
    }

    public function test_storage_test_matches_polyglot_request_fixture(): void
    {
        $fixture = self::fixture('storage-test-parity.json', 'storage.test');
        $client = new ExternalStorageParityClient($fixture['response_body']);
        $command = new TestCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute($fixture['cli']['argv']));

        self::assertSame($fixture['request']['method'], $client->lastMethod);
        self::assertSame($fixture['request']['path'], $client->lastPath);
        self::assertSame($fixture['request']['body'], $client->lastBody);

        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($fixture['response_body'], $decoded);

        $semantic = $fixture['semantic_body'];
        self::assertSame($semantic['status'], $decoded['status'] ?? null);
        self::assertSame($semantic['driver'], $decoded['driver'] ?? null);
        self::assertSame($semantic['small_payload_bytes'], $decoded['small_payload']['bytes'] ?? null);
        self::assertSame($semantic['large_payload_reference_uri'], $decoded['large_payload']['reference_uri'] ?? null);
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

final class ExternalStorageParityClient extends ServerClient
{
    public ?string $lastMethod = null;

    public ?string $lastPath = null;

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
