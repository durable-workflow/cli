<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\NamespaceCommand\SetStorageDriverCommand;
use DurableWorkflow\Cli\Commands\StorageCommand\TestCommand;
use DurableWorkflow\Cli\Support\ServerClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class ExternalStorageCommandTest extends TestCase
{
    public function test_namespace_set_storage_driver_sends_policy_body(): void
    {
        $client = new ExternalStorageFakeClient([
            'name' => 'billing',
            'external_payload_storage' => [
                'driver' => 's3',
                'enabled' => true,
                'threshold_bytes' => 2097152,
                'config' => [
                    'bucket' => 'dw-payloads',
                    'prefix' => 'billing/',
                ],
            ],
        ]);

        $command = new SetStorageDriverCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'name' => 'billing',
            'driver' => 's3',
            '--bucket' => 'dw-payloads',
            '--prefix' => 'billing/',
            '--region' => 'us-east-1',
            '--threshold-bytes' => '2097152',
        ]));

        self::assertSame('/namespaces/billing/external-storage', $client->lastPutPath);
        self::assertSame('s3', $client->lastPutBody['driver']);
        self::assertTrue($client->lastPutBody['enabled']);
        self::assertSame(2097152, $client->lastPutBody['threshold_bytes']);
        self::assertSame('dw-payloads', $client->lastPutBody['config']['bucket']);
        self::assertSame('billing/', $client->lastPutBody['config']['prefix']);
        self::assertSame('us-east-1', $client->lastPutBody['config']['region']);
        self::assertStringContainsString('External storage updated for namespace: billing', $tester->getDisplay());
        self::assertStringContainsString('Driver: s3', $tester->getDisplay());
    }

    public function test_namespace_set_storage_driver_can_disable_policy_and_render_json(): void
    {
        $client = new ExternalStorageFakeClient([
            'name' => 'dev',
            'external_payload_storage' => [
                'driver' => 'local',
                'enabled' => false,
            ],
        ]);

        $command = new SetStorageDriverCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'name' => 'dev',
            'driver' => 'local',
            '--disable' => true,
            '--json' => true,
        ]));

        self::assertSame('/namespaces/dev/external-storage', $client->lastPutPath);
        self::assertFalse($client->lastPutBody['enabled']);

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded);
        self::assertFalse($decoded['external_payload_storage']['enabled']);
    }

    public function test_storage_test_posts_round_trip_request(): void
    {
        $client = new ExternalStorageFakeClient([
            'status' => 'passed',
            'namespace' => 'billing',
            'driver' => 's3',
            'small_payload' => [
                'status' => 'passed',
                'bytes' => 128,
                'sha256' => str_repeat('a', 64),
            ],
            'large_payload' => [
                'status' => 'passed',
                'bytes' => 2097152,
                'sha256' => str_repeat('b', 64),
                'reference_uri' => 's3://dw-payloads/billing/test-object',
            ],
        ]);

        $command = new TestCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--driver' => 's3',
            '--small-bytes' => '128',
            '--large-bytes' => '2097152',
        ]));

        self::assertSame('/storage/test', $client->lastPostPath);
        self::assertSame('s3', $client->lastPostBody['driver']);
        self::assertSame(128, $client->lastPostBody['small_payload_bytes']);
        self::assertSame(2097152, $client->lastPostBody['large_payload_bytes']);
        self::assertStringContainsString('External storage round trip: passed', $tester->getDisplay());
        self::assertStringContainsString('reference=s3://dw-payloads/billing/test-object', $tester->getDisplay());
    }

    public function test_storage_test_renders_json_output(): void
    {
        $client = new ExternalStorageFakeClient([
            'status' => 'passed',
            'driver' => 'local',
            'small_payload' => [
                'status' => 'passed',
                'bytes' => 64,
                'sha256' => str_repeat('a', 64),
            ],
            'large_payload' => [
                'status' => 'passed',
                'bytes' => 1024,
                'sha256' => str_repeat('b', 64),
            ],
        ]);

        $command = new TestCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--small-bytes' => '64',
            '--large-bytes' => '1024',
            '--json' => true,
        ]));

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded);
        self::assertSame('passed', $decoded['status']);
        self::assertSame('local', $decoded['driver']);
    }

    public function test_storage_commands_reject_unknown_drivers(): void
    {
        $namespaceCommand = new SetStorageDriverCommand();
        $namespaceCommand->setServerClient(new ExternalStorageFakeClient([]));

        $namespaceTester = new CommandTester($namespaceCommand);
        self::assertSame(Command::FAILURE, $namespaceTester->execute([
            'name' => 'billing',
            'driver' => 'ftp',
        ]));
        self::assertStringContainsString('driver must be one of: local, s3, gcs, azure', $namespaceTester->getDisplay());

        $storageCommand = new TestCommand();
        $storageCommand->setServerClient(new ExternalStorageFakeClient([]));

        $storageTester = new CommandTester($storageCommand);
        self::assertSame(Command::FAILURE, $storageTester->execute([
            '--driver' => 'ftp',
        ]));
        self::assertStringContainsString('driver must be one of: local, s3, gcs, azure', $storageTester->getDisplay());
    }
}

class ExternalStorageFakeClient extends ServerClient
{
    /** @var array<string, mixed> */
    public array $lastPostBody = [];

    public string $lastPostPath = '';

    /** @var array<string, mixed> */
    public array $lastPutBody = [];

    public string $lastPutPath = '';

    /** @param array<string, mixed> $payload */
    public function __construct(
        private readonly array $payload,
    ) {}

    public function post(string $path, array $body = []): array
    {
        $this->lastPostPath = $path;
        $this->lastPostBody = $body;

        return $this->payload;
    }

    public function put(string $path, array $body = []): array
    {
        $this->lastPutPath = $path;
        $this->lastPutBody = $body;

        return $this->payload;
    }
}
