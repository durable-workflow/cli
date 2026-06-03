<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli;

use DurableWorkflow\Cli\Commands\ActivityCommand;
use DurableWorkflow\Cli\Commands\BaseCommand;
use DurableWorkflow\Cli\Commands\BridgeCommand;
use DurableWorkflow\Cli\Commands\DebugCommand;
use DurableWorkflow\Cli\Commands\DoctorCommand;
use DurableWorkflow\Cli\Commands\UpgradeCommand;
use DurableWorkflow\Cli\Commands\EnvCommand;
use DurableWorkflow\Cli\Commands\NamespaceCommand;
use DurableWorkflow\Cli\Commands\QueryTaskCommand;
use DurableWorkflow\Cli\Commands\RuntimeCheckCommand;
use DurableWorkflow\Cli\Commands\ScheduleCommand;
use DurableWorkflow\Cli\Commands\SchemaCommand;
use DurableWorkflow\Cli\Commands\SearchAttributeCommand;
use DurableWorkflow\Cli\Commands\ServerCommand;
use DurableWorkflow\Cli\Commands\StorageCommand;
use DurableWorkflow\Cli\Commands\SystemCommand;
use DurableWorkflow\Cli\Commands\TaskQueueCommand;
use DurableWorkflow\Cli\Commands\WatchCommand;
use DurableWorkflow\Cli\Commands\WorkerCommand;
use DurableWorkflow\Cli\Commands\WorkflowCommand;
use DurableWorkflow\Cli\Commands\WorkflowTaskCommand;
use DurableWorkflow\Cli\Support\CompatibilityDiagnostics;
use DurableWorkflow\Cli\Support\ProfileResolver;
use DurableWorkflow\Cli\Support\ProfileStore;
use DurableWorkflow\Cli\Support\ResolvedConnection;
use DurableWorkflow\Cli\Support\ServerClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Application extends ConsoleApplication
{
    private const GROUPED_COMMAND_ALIASES = [
        'workflow' => [
            'list' => 'workflow:list',
        ],
        'workflows' => [
            'list' => 'workflow:list',
        ],
        'schedule' => [
            'backfill' => 'schedule:backfill',
            'create' => 'schedule:create',
            'delete' => 'schedule:delete',
            'describe' => 'schedule:describe',
            'history' => 'schedule:history',
            'list' => 'schedule:list',
            'pause' => 'schedule:pause',
            'resume' => 'schedule:resume',
            'trigger' => 'schedule:trigger',
            'update' => 'schedule:update',
        ],
        'schedules' => [
            'backfill' => 'schedule:backfill',
            'create' => 'schedule:create',
            'delete' => 'schedule:delete',
            'describe' => 'schedule:describe',
            'history' => 'schedule:history',
            'list' => 'schedule:list',
            'pause' => 'schedule:pause',
            'resume' => 'schedule:resume',
            'trigger' => 'schedule:trigger',
            'update' => 'schedule:update',
        ],
        'search-attribute' => [
            'create' => 'search-attribute:create',
            'delete' => 'search-attribute:delete',
            'list' => 'search-attribute:list',
        ],
        'search-attributes' => [
            'create' => 'search-attribute:create',
            'delete' => 'search-attribute:delete',
            'list' => 'search-attribute:list',
        ],
    ];

    private const OPTIONS_WITH_SEPARATE_VALUES = [
        '--env',
        '--namespace',
        '--output',
        '--server',
        '--tls-verify',
        '--token',
        '-s',
    ];

    private bool $sessionCompatibilityWarningChecked = false;

    /**
     * @var (callable(ResolvedConnection): ServerClient)|null
     */
    private mixed $serverClientFactory = null;

    /**
     * @param  (callable(ResolvedConnection): ServerClient)|null  $serverClientFactory
     */
    public function __construct(?callable $serverClientFactory = null)
    {
        parent::__construct('dw', BuildInfo::consoleVersion());

        $this->serverClientFactory = $serverClientFactory;

        $commands = [
            // Server management
            new ServerCommand\HealthCommand(),
            new ServerCommand\InfoCommand(),
            new ServerCommand\StartDevCommand(),
            new DoctorCommand(),
            new DebugCommand(),
            new UpgradeCommand(),
            new RuntimeCheckCommand(),

            // Workflow operations
            new WorkflowCommand\StartCommand(),
            new WorkflowCommand\ListCommand(),
            new WorkflowCommand\DescribeCommand(),
            new WorkflowCommand\SignalCommand(),
            new WorkflowCommand\QueryCommand(),
            new WorkflowCommand\UpdateCommand(),
            new WorkflowCommand\CancelCommand(),
            new WorkflowCommand\TerminateCommand(),
            new WorkflowCommand\RepairCommand(),
            new WorkflowCommand\ArchiveCommand(),
            new WorkflowCommand\ListRunsCommand(),
            new WorkflowCommand\ShowRunCommand(),
            new WorkflowCommand\HistoryCommand(),
            new WorkflowCommand\HistoryExportCommand(),

            // Environment profiles (local config)
            new EnvCommand\ListCommand(),
            new EnvCommand\SetCommand(),
            new EnvCommand\UseCommand(),
            new EnvCommand\ShowCommand(),
            new EnvCommand\DeleteCommand(),

            // Namespace management
            new NamespaceCommand\ListCommand(),
            new NamespaceCommand\CreateCommand(),
            new NamespaceCommand\DescribeCommand(),
            new NamespaceCommand\UpdateCommand(),
            new NamespaceCommand\DeleteCommand(),
            new NamespaceCommand\SetStorageDriverCommand(),

            // External payload storage diagnostics
            new StorageCommand\TestCommand(),

            // Activity operations
            new ActivityCommand\CompleteCommand(),
            new ActivityCommand\FailCommand(),

            // Bridge adapter ingress and handoff
            new BridgeCommand\WebhookCommand(),

            // Schedule management
            new ScheduleCommand\ListCommand(),
            new ScheduleCommand\CreateCommand(),
            new ScheduleCommand\DescribeCommand(),
            new ScheduleCommand\DeleteCommand(),
            new ScheduleCommand\PauseCommand(),
            new ScheduleCommand\ResumeCommand(),
            new ScheduleCommand\TriggerCommand(),
            new ScheduleCommand\UpdateCommand(),
            new ScheduleCommand\BackfillCommand(),
            new ScheduleCommand\HistoryCommand(),

            // Worker management
            new WorkerCommand\RegisterCommand(),
            new WorkerCommand\ListCommand(),
            new WorkerCommand\DescribeCommand(),
            new WorkerCommand\DeregisterCommand(),

            // Worker protocol diagnostics
            new WorkflowTaskCommand\PollCommand(),
            new WorkflowTaskCommand\CompleteCommand(),
            new WorkflowTaskCommand\FailCommand(),
            new WorkflowTaskCommand\HistoryCommand(),
            new QueryTaskCommand\PollCommand(),
            new QueryTaskCommand\CompleteCommand(),
            new QueryTaskCommand\FailCommand(),

            // Task queue inspection
            new TaskQueueCommand\ListCommand(),
            new TaskQueueCommand\DescribeCommand(),
            new TaskQueueCommand\BuildIdsCommand(),
            new TaskQueueCommand\PromoteCommand(),
            new TaskQueueCommand\DrainCommand(),
            new TaskQueueCommand\ResumeCommand(),

            // Search attribute management
            new SearchAttributeCommand\ListCommand(),
            new SearchAttributeCommand\CreateCommand(),
            new SearchAttributeCommand\DeleteCommand(),

            // System operations
            new SystemCommand\OperatorMetricsCommand(),
            new SystemCommand\RepairStatusCommand(),
            new SystemCommand\RepairPassCommand(),
            new SystemCommand\ActivityTimeoutStatusCommand(),
            new SystemCommand\ActivityTimeoutPassCommand(),
            new SystemCommand\RetentionStatusCommand(),
            new SystemCommand\RetentionPassCommand(),

            // Published machine-readable contracts
            new SchemaCommand\ListCommand(),
            new SchemaCommand\ShowCommand(),
            new SchemaCommand\ManifestCommand(),

            // Long-running operator views
            new WatchCommand(),
        ];

        if ($serverClientFactory !== null) {
            foreach ($commands as $command) {
                if ($command instanceof BaseCommand) {
                    $command->setServerClientFactory($serverClientFactory);
                }
            }
        }

        $this->addCommands($commands);
    }

    public function doRun(InputInterface $input, OutputInterface $output): int
    {
        $input = $this->normalizeGroupedCommandInput($input);

        if (true === $input->hasParameterOption(['--version', '-V'], true)) {
            $output->writeln($this->getLongVersion());
            $this->emitVersionCompatibilityWarning($output);

            return 0;
        }

        return parent::doRun($input, $output);
    }

    private function normalizeGroupedCommandInput(InputInterface $input): InputInterface
    {
        if (! $input instanceof ArgvInput) {
            return $input;
        }

        $tokens = method_exists($input, 'getRawTokens')
            ? $input->getRawTokens()
            : array_slice($_SERVER['argv'] ?? [], 1);

        if (! is_array($tokens) || count($tokens) < 2) {
            return $input;
        }

        $rewritten = $this->rewriteGroupedCommandTokens($tokens);
        if ($rewritten === null) {
            return $input;
        }

        return new ArgvInput(array_merge([$_SERVER['argv'][0] ?? 'dw'], $rewritten));
    }

    /**
     * @param  list<string>  $tokens
     * @return list<string>|null
     */
    private function rewriteGroupedCommandTokens(array $tokens): ?array
    {
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = (string) $tokens[$i];

            if ($token === '--') {
                return null;
            }

            if (str_starts_with($token, '-')) {
                if ($this->optionConsumesNextToken($token) && isset($tokens[$i + 1])) {
                    $i++;
                }

                continue;
            }

            $verb = isset($tokens[$i + 1]) ? (string) $tokens[$i + 1] : null;
            if ($verb !== null && isset(self::GROUPED_COMMAND_ALIASES[$token][$verb])) {
                $tokens[$i] = self::GROUPED_COMMAND_ALIASES[$token][$verb];
                array_splice($tokens, $i + 1, 1);

                return array_values($tokens);
            }

            return null;
        }

        return null;
    }

    private function optionConsumesNextToken(string $token): bool
    {
        if (str_contains($token, '=')) {
            return false;
        }

        return in_array($token, self::OPTIONS_WITH_SEPARATE_VALUES, true);
    }

    protected function doRunCommand(Command $command, InputInterface $input, OutputInterface $output): int
    {
        if (
            ! $this->sessionCompatibilityWarningChecked
            && $command instanceof BaseCommand
            && ! $input->hasParameterOption(['--help', '-h'], true)
            && $command->emitsSessionCompatibilityWarning()
        ) {
            $this->sessionCompatibilityWarningChecked = true;
            $command->emitSessionCompatibilityWarning($input, $output);
        }

        return parent::doRunCommand($command, $input, $output);
    }

    private function emitVersionCompatibilityWarning(OutputInterface $output): void
    {
        if (! $this->hasExplicitVersionWarningTarget()) {
            return;
        }

        try {
            $resolved = (new ProfileResolver(new ProfileStore()))->resolve(
                flagEnv: null,
                flagServer: null,
                flagNamespace: null,
                flagToken: null,
            );
            $client = is_callable($this->serverClientFactory)
                ? ($this->serverClientFactory)($resolved)
                : new ServerClient(
                    baseUrl: $resolved->server,
                    token: $resolved->token,
                    namespace: $resolved->namespace,
                    tlsVerify: $resolved->tlsVerify,
                    timeout: 1.0,
                );
            $warnings = CompatibilityDiagnostics::warnings($client->clusterInfoUnchecked(), BuildInfo::version());
        } catch (\Throwable) {
            return;
        }

        if ($warnings === []) {
            return;
        }

        $this->writeCompatibilityWarning($output, $warnings[0].' Run `dw doctor` for details.');
    }

    private function hasExplicitVersionWarningTarget(): bool
    {
        return self::envString('DURABLE_WORKFLOW_SERVER_URL') !== null
            || self::envString('DW_ENV') !== null;
    }

    private function writeCompatibilityWarning(OutputInterface $output, string $message): void
    {
        $target = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $target->writeln('<comment>'.$message.'</comment>');
    }

    private static function envString(string $name): ?string
    {
        $value = $_ENV[$name] ?? getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
