<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli;

use DurableWorkflow\Cli\Commands\ActivityCommand;
use DurableWorkflow\Cli\Commands\NamespaceCommand;
use DurableWorkflow\Cli\Commands\ScheduleCommand;
use DurableWorkflow\Cli\Commands\SearchAttributeCommand;
use DurableWorkflow\Cli\Commands\ServerCommand;
use DurableWorkflow\Cli\Commands\SystemCommand;
use DurableWorkflow\Cli\Commands\TaskQueueCommand;
use DurableWorkflow\Cli\Commands\WorkflowCommand;
use Symfony\Component\Console\Application as ConsoleApplication;

class Application extends ConsoleApplication
{
    public function __construct()
    {
        parent::__construct('Durable Workflow CLI', '0.1.0');

        $this->addCommands([
            // Server management
            new ServerCommand\HealthCommand(),
            new ServerCommand\InfoCommand(),
            new ServerCommand\StartDevCommand(),

            // Workflow operations
            new WorkflowCommand\StartCommand(),
            new WorkflowCommand\ListCommand(),
            new WorkflowCommand\DescribeCommand(),
            new WorkflowCommand\SignalCommand(),
            new WorkflowCommand\QueryCommand(),
            new WorkflowCommand\UpdateCommand(),
            new WorkflowCommand\CancelCommand(),
            new WorkflowCommand\TerminateCommand(),
            new WorkflowCommand\HistoryCommand(),

            // Namespace management
            new NamespaceCommand\ListCommand(),
            new NamespaceCommand\CreateCommand(),
            new NamespaceCommand\DescribeCommand(),
            new NamespaceCommand\UpdateCommand(),

            // Activity operations
            new ActivityCommand\CompleteCommand(),
            new ActivityCommand\FailCommand(),

            // Schedule management
            new ScheduleCommand\ListCommand(),
            new ScheduleCommand\CreateCommand(),
            new ScheduleCommand\DescribeCommand(),
            new ScheduleCommand\DeleteCommand(),
            new ScheduleCommand\PauseCommand(),
            new ScheduleCommand\ResumeCommand(),
            new ScheduleCommand\TriggerCommand(),
            new ScheduleCommand\BackfillCommand(),

            // Task queue inspection
            new TaskQueueCommand\ListCommand(),
            new TaskQueueCommand\DescribeCommand(),

            // Search attribute management
            new SearchAttributeCommand\ListCommand(),
            new SearchAttributeCommand\CreateCommand(),
            new SearchAttributeCommand\DeleteCommand(),

            // System operations
            new SystemCommand\RepairStatusCommand(),
            new SystemCommand\RepairPassCommand(),
        ]);
    }
}
