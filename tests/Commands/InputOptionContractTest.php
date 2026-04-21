<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\ActivityCommand\CompleteCommand as ActivityCompleteCommand;
use DurableWorkflow\Cli\Commands\ScheduleCommand\CreateCommand as ScheduleCreateCommand;
use DurableWorkflow\Cli\Commands\ScheduleCommand\UpdateCommand as ScheduleUpdateCommand;
use DurableWorkflow\Cli\Commands\WorkflowCommand\QueryCommand;
use DurableWorkflow\Cli\Commands\WorkflowCommand\SignalCommand;
use DurableWorkflow\Cli\Commands\WorkflowCommand\StartCommand;
use DurableWorkflow\Cli\Commands\WorkflowCommand\UpdateCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;

final class InputOptionContractTest extends TestCase
{
    /**
     * @dataProvider inputCommands
     */
    public function test_input_accepting_commands_share_the_same_option_contract(Command $command): void
    {
        $definition = $command->getDefinition();

        self::assertTrue($definition->hasOption('input'));
        self::assertSame('i', $definition->getOption('input')->getShortcut());
        self::assertTrue($definition->hasOption('input-file'));
        self::assertTrue($definition->hasOption('input-encoding'));
        self::assertSame('json', $definition->getOption('input-encoding')->getDefault());
    }

    public function test_activity_complete_no_longer_exposes_result_alias(): void
    {
        $definition = (new ActivityCompleteCommand())->getDefinition();

        self::assertFalse($definition->hasOption('result'));
    }

    /**
     * @return iterable<string, array{Command}>
     */
    public static function inputCommands(): iterable
    {
        yield 'workflow:start' => [new StartCommand()];
        yield 'workflow:signal' => [new SignalCommand()];
        yield 'workflow:query' => [new QueryCommand()];
        yield 'workflow:update' => [new UpdateCommand()];
        yield 'activity:complete' => [new ActivityCompleteCommand()];
        yield 'schedule:create' => [new ScheduleCreateCommand()];
        yield 'schedule:update' => [new ScheduleUpdateCommand()];
    }
}
