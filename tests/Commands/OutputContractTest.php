<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Application;
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
        self::assertSame('throwing:test', $envelope['command']);
        self::assertSame(404, $envelope['status_code']);
        self::assertSame('resource.not_found', $envelope['recommendations'][0]['id'] ?? null);
        self::assertSame('dw throwing:test --help', $envelope['recommendations'][0]['command'] ?? null);
    }

    /**
     * @dataProvider namespaceScopedCommandNames
     */
    public function test_json_error_envelope_names_selected_namespace_for_scoped_commands(string $commandName): void
    {
        $command = new ThrowingOutputCommand(
            new ServerHttpException('Workflow not found', 404),
            commandName: $commandName,
        );
        $tester = new CommandTester($command);

        $exit = $tester->execute([
            '--namespace' => 'tenant-b',
            '--output' => 'json',
        ]);

        self::assertSame(ExitCode::NOT_FOUND, $exit);

        $envelope = json_decode(trim($tester->getDisplay()), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($commandName, $envelope['command']);
        self::assertSame('tenant-b', $envelope['namespace']);
    }

    public function test_json_error_envelope_preserves_typed_server_reason_and_body(): void
    {
        $body = [
            'workflow_id' => 'counter-1',
            'signal_name' => 'increment',
            'reason' => 'invalid_signal_arguments',
            'message' => 'Signal argument validation failed.',
            'validation_errors' => [
                'n' => ['The n argument must be an integer.'],
            ],
        ];
        $command = new ThrowingOutputCommand(new ServerHttpException('bad signal', 422, body: $body));
        $tester = new CommandTester($command);

        $exit = $tester->execute(['--output' => 'json']);

        self::assertSame(ExitCode::INVALID, $exit);

        $envelope = json_decode(trim($tester->getDisplay()), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('invalid_signal_arguments', $envelope['reason']);
        self::assertSame($body['validation_errors'], $envelope['validation_errors']);
        self::assertSame($body, $envelope['server_response']);
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

    /**
     * @dataProvider namespaceScopedCommandNames
     */
    public function test_human_error_names_selected_namespace_for_scoped_commands(string $commandName): void
    {
        $command = new ThrowingOutputCommand(
            new ServerHttpException('Workflow not found', 404),
            commandName: $commandName,
        );
        $tester = new CommandTester($command);

        $exit = $tester->execute([
            '--namespace' => 'tenant-b',
        ], ['capture_stderr_separately' => true]);

        self::assertSame(ExitCode::NOT_FOUND, $exit);
        self::assertSame('', trim($tester->getDisplay()), 'stdout must be empty on error in table mode');
        self::assertStringContainsString('Workflow not found', $tester->getErrorOutput());
        self::assertStringContainsString('Namespace: tenant-b', $tester->getErrorOutput());
    }

    /**
     * @dataProvider defaultScopeCommandNames
     */
    public function test_json_error_envelope_omits_namespace_for_default_scope_commands(string $commandName): void
    {
        $command = new ThrowingOutputCommand(
            new ServerHttpException('Not found', 404),
            commandName: $commandName,
        );
        $tester = new CommandTester($command);

        $tester->execute([
            '--namespace' => 'tenant-b',
            '--output' => 'json',
        ]);

        $envelope = json_decode(trim($tester->getDisplay()), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($commandName, $envelope['command']);
        self::assertArrayNotHasKey('namespace', $envelope);
    }

    /**
     * @dataProvider defaultScopeCommandNames
     */
    public function test_human_error_omits_namespace_for_default_scope_commands(string $commandName): void
    {
        $command = new ThrowingOutputCommand(
            new ServerHttpException('Not found', 404),
            commandName: $commandName,
        );
        $tester = new CommandTester($command);

        $tester->execute([
            '--namespace' => 'tenant-b',
        ], ['capture_stderr_separately' => true]);

        self::assertStringContainsString('Not found', $tester->getErrorOutput());
        self::assertStringNotContainsString('Namespace: tenant-b', $tester->getErrorOutput());
    }

    public function test_namespace_error_scope_metadata_covers_registered_base_commands(): void
    {
        $scoped = BaseCommand::namespaceScopedCommandNamesForErrors();
        $default = BaseCommand::defaultScopeCommandNamesForErrors();

        self::assertSame([], array_values(array_intersect($scoped, $default)), 'commands must have one namespace error scope');

        $application = new Application();
        $registered = [];

        foreach ($application->all() as $command) {
            if (! $command instanceof BaseCommand) {
                continue;
            }

            $name = $command->getName();
            if ($name !== null && $name !== '') {
                $registered[$name] = true;
            }
        }

        $declared = array_fill_keys(array_merge($scoped, $default), true);
        ksort($registered);
        ksort($declared);

        self::assertSame(
            array_keys($registered),
            array_keys($declared),
            'every BaseCommand must explicitly declare whether errors include namespace context',
        );
    }

    public function test_namespace_error_scope_metadata_matches_scoped_expectations(): void
    {
        $expected = self::commandNamesFromCases(self::namespaceScopedCommandNames());
        $actual = BaseCommand::namespaceScopedCommandNamesForErrors();
        sort($actual);

        self::assertSame($expected, $actual);
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

    /**
     * @return iterable<string, array{string}>
     */
    public static function namespaceScopedCommandNames(): iterable
    {
        yield 'activity complete' => ['activity:complete'];
        yield 'activity fail' => ['activity:fail'];
        yield 'bridge adapter webhook' => ['bridge:webhook'];
        yield 'debug workflow diagnostics' => ['debug'];
        yield 'query task complete' => ['query-task:complete'];
        yield 'query task fail' => ['query-task:fail'];
        yield 'query task poll' => ['query-task:poll'];
        yield 'schedule backfill' => ['schedule:backfill'];
        yield 'schedule create' => ['schedule:create'];
        yield 'schedule delete' => ['schedule:delete'];
        yield 'schedule describe' => ['schedule:describe'];
        yield 'schedule history' => ['schedule:history'];
        yield 'schedule list' => ['schedule:list'];
        yield 'schedule pause' => ['schedule:pause'];
        yield 'schedule resume' => ['schedule:resume'];
        yield 'schedule trigger' => ['schedule:trigger'];
        yield 'schedule update' => ['schedule:update'];
        yield 'search attribute create' => ['search-attribute:create'];
        yield 'search attribute delete' => ['search-attribute:delete'];
        yield 'search attribute list' => ['search-attribute:list'];
        yield 'storage diagnostics' => ['storage:test'];
        yield 'system activity timeout pass' => ['system:activity-timeout-pass'];
        yield 'system activity timeout status' => ['system:activity-timeout-status'];
        yield 'system operator metrics' => ['system:operator-metrics'];
        yield 'system repair pass' => ['system:repair-pass'];
        yield 'system repair status' => ['system:repair-status'];
        yield 'system retention pass' => ['system:retention-pass'];
        yield 'system retention status' => ['system:retention-status'];
        yield 'task queue build ids' => ['task-queue:build-ids'];
        yield 'task queue describe' => ['task-queue:describe'];
        yield 'task queue drain' => ['task-queue:drain'];
        yield 'task queue list' => ['task-queue:list'];
        yield 'task queue resume' => ['task-queue:resume'];
        yield 'watch workflow' => ['watch'];
        yield 'worker deregister' => ['worker:deregister'];
        yield 'worker describe' => ['worker:describe'];
        yield 'worker list' => ['worker:list'];
        yield 'worker register' => ['worker:register'];
        yield 'workflow archive' => ['workflow:archive'];
        yield 'workflow cancel' => ['workflow:cancel'];
        yield 'workflow describe' => ['workflow:describe'];
        yield 'workflow history' => ['workflow:history'];
        yield 'workflow history export' => ['workflow:history-export'];
        yield 'workflow list' => ['workflow:list'];
        yield 'workflow list runs' => ['workflow:list-runs'];
        yield 'workflow query' => ['workflow:query'];
        yield 'workflow repair' => ['workflow:repair'];
        yield 'workflow show run' => ['workflow:show-run'];
        yield 'workflow signal' => ['workflow:signal'];
        yield 'workflow start' => ['workflow:start'];
        yield 'workflow terminate' => ['workflow:terminate'];
        yield 'workflow update' => ['workflow:update'];
        yield 'workflow task complete' => ['workflow-task:complete'];
        yield 'workflow task fail' => ['workflow-task:fail'];
        yield 'workflow task history' => ['workflow-task:history'];
        yield 'workflow task poll' => ['workflow-task:poll'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function defaultScopeCommandNames(): iterable
    {
        yield 'doctor' => ['doctor'];
        yield 'environment profile delete' => ['env:delete'];
        yield 'environment profile list' => ['env:list'];
        yield 'environment profile set' => ['env:set'];
        yield 'environment profile show' => ['env:show'];
        yield 'environment profile use' => ['env:use'];
        yield 'namespace create' => ['namespace:create'];
        yield 'namespace delete' => ['namespace:delete'];
        yield 'namespace describe' => ['namespace:describe'];
        yield 'namespace list' => ['namespace:list'];
        yield 'namespace set storage driver' => ['namespace:set-storage-driver'];
        yield 'namespace update' => ['namespace:update'];
        yield 'runtime check' => ['runtime:check'];
        yield 'schema list' => ['schema:list'];
        yield 'schema manifest' => ['schema:manifest'];
        yield 'schema show' => ['schema:show'];
        yield 'server health' => ['server:health'];
        yield 'server info' => ['server:info'];
        yield 'server start dev' => ['server:start-dev'];
        yield 'upgrade' => ['upgrade'];
        yield 'unknown local command family' => ['throwing:test'];
    }

    /**
     * @param  iterable<string, array{string}>  $cases
     * @return list<string>
     */
    private static function commandNamesFromCases(iterable $cases): array
    {
        $names = [];

        foreach ($cases as $case) {
            $names[] = $case[0];
        }

        sort($names);

        return $names;
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
        string $commandName = 'throwing:test',
    ) {
        parent::__construct($commandName);
    }

    protected function configure(): void
    {
        parent::configure();

        if ($this->enableJsonFlag) {
            $this->addJsonOption();
        }
    }

    protected function client(InputInterface $input, ?float $timeout = null): ServerClient
    {
        throw new \LogicException('not used');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        throw $this->toThrow;
    }
}
