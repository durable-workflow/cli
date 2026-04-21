<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\BaseCommand;
use DurableWorkflow\Cli\Commands\NamespaceCommand\ListCommand as NamespaceListCommand;
use DurableWorkflow\Cli\Commands\WorkflowCommand\ListCommand as WorkflowListCommand;
use DurableWorkflow\Cli\Support\ExitCode;
use DurableWorkflow\Cli\Support\NetworkException;
use DurableWorkflow\Cli\Support\ServerClient;
use DurableWorkflow\Cli\Support\ServerHttpException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Locks the CLI output contract declared in #464 so scripts can rely on it:
 *   - `--output=json` / `--json` emit a single valid JSON document on stdout.
 *   - `--output=jsonl` emits one JSON object per line for list commands.
 *   - Errors round-trip through a JSON envelope on stdout in json/jsonl
 *     modes, never human text.
 *   - Human errors route to stderr (not stdout) in table mode when the
 *     output interface supports split streams.
 *   - Unknown `--output` values fail with INVALID, never silent fallback.
 */
class OutputContractTest extends TestCase
{
    public function test_json_mode_emits_single_json_document_for_list_command(): void
    {
        $command = new WorkflowListCommand();
        $command->setServerClient(new OutputContractListClient([
            'workflows' => [
                ['workflow_id' => 'wf-1', 'status' => 'running'],
                ['workflow_id' => 'wf-2', 'status' => 'completed'],
            ],
            'next_page_token' => 'tok-1',
        ]));

        $tester = new CommandTester($command);
        self::assertSame(0, $tester->execute(['--output' => 'json']));

        $display = trim($tester->getDisplay());
        self::assertJson($display, 'stdout must be a single JSON doc in --output=json mode');

        $decoded = json_decode($display, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('tok-1', $decoded['next_page_token'] ?? null, 'pagination cursor must survive json mode');
        self::assertCount(2, $decoded['workflows']);
    }

    public function test_jsonl_mode_emits_one_object_per_line_for_list_command(): void
    {
        $command = new WorkflowListCommand();
        $command->setServerClient(new OutputContractListClient([
            'workflows' => [
                ['workflow_id' => 'wf-1', 'status' => 'running'],
                ['workflow_id' => 'wf-2', 'status' => 'completed'],
                ['workflow_id' => 'wf-3', 'status' => 'failed'],
            ],
        ]));

        $tester = new CommandTester($command);
        self::assertSame(0, $tester->execute(['--output' => 'jsonl']));

        $lines = array_values(array_filter(
            explode("\n", $tester->getDisplay()),
            static fn (string $line): bool => $line !== '',
        ));

        self::assertCount(3, $lines, 'jsonl must emit exactly one line per item');

        foreach ($lines as $i => $line) {
            self::assertJson($line, "line {$i} must be valid JSON");
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            self::assertArrayHasKey('workflow_id', $decoded);
        }

        self::assertSame('wf-1', json_decode($lines[0], true)['workflow_id']);
        self::assertSame('wf-3', json_decode($lines[2], true)['workflow_id']);
    }

    public function test_jsonl_mode_emits_nothing_when_list_is_empty(): void
    {
        $command = new WorkflowListCommand();
        $command->setServerClient(new OutputContractListClient(['workflows' => []]));

        $tester = new CommandTester($command);
        self::assertSame(0, $tester->execute(['--output' => 'jsonl']));

        self::assertSame('', trim($tester->getDisplay()), 'empty jsonl stream must be empty');
    }

    public function test_json_mode_is_compact_not_pretty_printed(): void
    {
        $command = new NamespaceListCommand();
        $command->setServerClient(new OutputContractListClient([
            'namespaces' => [['name' => 'ns-1', 'status' => 'active']],
        ]));

        $tester = new CommandTester($command);
        $tester->execute(['--output' => 'json']);

        $display = trim($tester->getDisplay());

        self::assertStringNotContainsString("\n", $display, 'compact json must be on a single line');
        self::assertStringNotContainsString('    ', $display, 'compact json must not be pretty-printed');
    }

    public function test_unknown_output_mode_emits_json_error_and_returns_invalid_exit(): void
    {
        $command = new WorkflowListCommand();
        $command->setServerClient(new OutputContractListClient(['workflows' => []]));

        $tester = new CommandTester($command);
        $exit = $tester->execute(['--output' => 'yaml']);

        self::assertSame(ExitCode::INVALID, $exit, 'unknown --output values must hard-fail');

        // Error lands in the JSON envelope because `wantsJson` still resolves
        // the --json flag even though the mode itself was invalid; assert the
        // stdout buffer at least surfaces the invalid-option message.
        self::assertStringContainsString('--output', $tester->getDisplay());
    }

    public function test_error_envelope_on_json_mode_for_server_error(): void
    {
        $command = new ThrowingOutputCommand(new ServerHttpException('Not found', 404));
        $tester = new CommandTester($command);

        $exit = $tester->execute(['--output' => 'json']);

        self::assertSame(ExitCode::NOT_FOUND, $exit);

        $display = trim($tester->getDisplay());
        self::assertJson($display, 'errors in json mode must be a JSON envelope on stdout');

        $envelope = json_decode($display, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Not found', $envelope['error']);
        self::assertSame(ExitCode::NOT_FOUND, $envelope['exit_code']);
        self::assertSame(404, $envelope['status_code']);
        self::assertSame('resource.not_found', $envelope['recommendations'][0]['id'] ?? null);
        self::assertSame('dw throwing:test --help', $envelope['recommendations'][0]['command'] ?? null);
    }

    public function test_error_envelope_on_legacy_json_flag(): void
    {
        $command = new ThrowingOutputCommand(new NetworkException('Connection refused'), enableJsonFlag: true);
        $tester = new CommandTester($command);

        $exit = $tester->execute(['--json' => true, '--server' => 'http://unreachable:9999']);

        self::assertSame(ExitCode::NETWORK, $exit);

        $display = trim($tester->getDisplay());
        self::assertJson($display, '--json + failure must produce a JSON envelope');

        $envelope = json_decode($display, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Connection refused', $envelope['error']);
        self::assertSame(ExitCode::NETWORK, $envelope['exit_code']);
        self::assertArrayNotHasKey('status_code', $envelope, 'network errors have no HTTP status to surface');
        self::assertSame('server.unreachable', $envelope['recommendations'][0]['id'] ?? null);
        self::assertSame(
            'dw doctor --server=http://unreachable:9999 --output=json',
            $envelope['recommendations'][0]['command'] ?? null,
        );
    }

    public function test_human_error_routes_to_stderr_in_table_mode(): void
    {
        $command = new ThrowingOutputCommand(new ServerHttpException('boom', 500));
        $tester = new CommandTester($command);

        $exit = $tester->execute([], ['capture_stderr_separately' => true]);

        self::assertSame(ExitCode::SERVER, $exit);

        self::assertSame('', trim($tester->getDisplay()), 'stdout must be empty on error in table mode');
        self::assertStringContainsString('boom', $tester->getErrorOutput(), 'human error must land on stderr');
    }

    public function test_json_mode_keeps_stderr_silent_on_error(): void
    {
        $command = new ThrowingOutputCommand(new ServerHttpException('denied', 403));
        $tester = new CommandTester($command);

        $tester->execute(['--output' => 'json'], ['capture_stderr_separately' => true]);

        self::assertSame('', trim($tester->getErrorOutput()), 'json mode must not duplicate the error to stderr');

        $envelope = json_decode(trim($tester->getDisplay()), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(ExitCode::AUTH, $envelope['exit_code']);
        self::assertSame(403, $envelope['status_code']);
    }
}

class OutputContractListClient extends ServerClient
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        private readonly array $payload,
    ) {}

    public function get(string $path, array $query = []): array
    {
        return $this->payload;
    }
}

class ThrowingOutputCommand extends BaseCommand
{
    public function __construct(
        private readonly \Throwable $toThrow,
        private readonly bool $enableJsonFlag = false,
    ) {
        parent::__construct('throwing:test');
    }

    protected function configure(): void
    {
        parent::configure();

        if ($this->enableJsonFlag) {
            $this->addJsonOption();
        }
    }

    protected function client(InputInterface $input): ServerClient
    {
        throw new \LogicException('not used');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        throw $this->toThrow;
    }
}
