<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Application;
use DurableWorkflow\Cli\Commands\ServiceCallCommand\DescribeCommand;
use DurableWorkflow\Cli\Support\OutputSchemaRegistry;
use DurableWorkflow\Cli\Support\ServerClient;
use DurableWorkflow\Cli\Support\ServerHttpException;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ServiceCallDescribeCommandTest extends TestCase
{
    public static function outcomes(): array
    {
        return [['started'], ['completed'], ['failed'], ['cancelled']];
    }

    #[DataProvider('outcomes')]
    public function test_json_preserves_original_outcomes_and_only_reads_one_scoped_call(string $status): void
    {
        $response = [
            'id' => 'call-17', 'namespace' => '017', 'status' => $status,
            'caller_namespace' => '0', 'caller_workflow_instance_id' => 'caller', 'caller_workflow_run_id' => 'original-caller',
            'target_namespace' => '017', 'linked_workflow_instance_id' => 'target', 'linked_workflow_run_id' => 'original-target',
            'call_deadline_at' => '2026-10-18T03:00:00.123456Z',
            'output' => ['long' => PHP_INT_MAX, 'null' => null, 'unicode' => 'λ雪'],
            'caller_observed_error_type' => 'OriginalServiceFailure', 'typed_error_message' => 'exact failure',
            'service_call_attempts' => [['attempt' => 1, 'status' => 'failed'], ['attempt' => 2, 'status' => 'completed']],
            'retry_attempt_count' => 2, 'output_payload_reference' => ['provider' => 'unavailable', 'key' => 'retained'],
        ];
        $client = new ServiceCallDescribeClient($response);
        $command = (new Application())->find('service-call:describe');
        self::assertInstanceOf(DescribeCommand::class, $command);
        $command->setServerClient($client);
        $tester = new CommandTester($command);

        self::assertSame(0, $tester->execute([
            'endpoint' => 'billing/017', 'service' => 'invoices λ', 'operation' => '0', 'call-id' => 'call-17',
            '--namespace' => '017', '--json' => true,
        ]));
        self::assertSame([['/service-endpoints/billing%2F017/services/invoices%20%CE%BB/operations/0/service-calls/call-17', []]], $client->reads);
        self::assertSame($response, json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
        self::assertTrue((new Validator())->validate(
            json_decode($tester->getDisplay(), flags: JSON_THROW_ON_ERROR),
            json_decode(json_encode(OutputSchemaRegistry::schema('service-call:describe'), JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR),
        )->isValid());
    }

    public function test_human_output_preserves_failure_identity_zero_and_console_markup(): void
    {
        $command = new DescribeCommand();
        $command->setServerClient(new ServiceCallDescribeClient([
            'namespace' => '0', 'id' => 'original', 'status' => 'failed', 'linked_workflow_run_id' => 'target-run',
            'caller_observed_error_type' => 'OriginalFailure', 'typed_error_message' => '<info>λ雪</info>',
            'retry_attempt_count' => 0, 'service_call_attempts' => [], 'output' => ['long' => PHP_INT_MAX, 'null' => null],
        ]));
        $tester = new CommandTester($command);
        self::assertSame(0, $tester->execute(['endpoint' => 'e', 'service' => 's', 'operation' => 'o', 'call-id' => 'original']));
        foreach (['Namespace: 0', 'Target Run: target-run', 'Retry Attempts: 0', 'OriginalFailure', '<info>λ雪</info>', (string) PHP_INT_MAX, '"null":null'] as $expected) {
            self::assertStringContainsString($expected, $tester->getDisplay());
        }
    }

    public function test_a_server_selector_refusal_is_never_rendered_as_a_success(): void
    {
        $client = new ServiceCallDescribeClient([]);
        $client->failure = new ServerHttpException('service_call_not_found', 404);
        $command = new DescribeCommand();
        $command->setServerClient($client);
        $tester = new CommandTester($command);
        self::assertSame(5, $tester->execute(['endpoint' => 'e', 'service' => 's', 'operation' => 'o', 'call-id' => 'wrong', '--namespace' => '017', '--json' => true]));
        self::assertStringContainsString('service_call_not_found', $tester->getDisplay());
        self::assertSame('017', json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR)['namespace']);
        self::assertCount(1, $client->reads);
    }
}

final class ServiceCallDescribeClient extends ServerClient
{
    public array $reads = [];
    public ?ServerHttpException $failure = null;

    public function __construct(private readonly array $response)
    {
        parent::__construct('http://localhost');
    }

    public function get(string $path, array $query = []): array
    {
        $this->reads[] = [$path, $query];
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->response;
    }
}
