<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\SchemaCommand\ListCommand;
use DurableWorkflow\Cli\Commands\SchemaCommand\ManifestCommand;
use DurableWorkflow\Cli\Commands\SchemaCommand\ShowCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class SchemaCommandTest extends TestCase
{
    public function test_list_command_includes_published_output_schemas(): void
    {
        $tester = new CommandTester(new ListCommand());

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $display = $tester->getDisplay();

        self::assertStringContainsString('workflow:list', $display);
        self::assertStringContainsString('workflow-list.schema.json', $display);
        self::assertStringContainsString('workflow:history-export', $display);
    }

    public function test_manifest_command_outputs_machine_readable_manifest(): void
    {
        $tester = new CommandTester(new ManifestCommand());

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $manifest = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('durable-workflow.cli.output-schema-manifest', $manifest['schema']);
        self::assertSame(1, $manifest['version']);
        self::assertSame(
            'schemas/output/workflow-list.schema.json',
            $manifest['commands']['workflow:list']['schema'],
        );
    }

    public function test_show_command_outputs_schema_for_command(): void
    {
        $tester = new CommandTester(new ShowCommand());

        self::assertSame(Command::SUCCESS, $tester->execute([
            'command-name' => 'workflow:list',
        ]));

        $schema = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(
            'https://durable-workflow.com/schemas/cli/output/workflow-list.schema.json',
            $schema['$id'],
        );
        self::assertSame(['workflows'], $schema['required']);
    }

    public function test_show_command_rejects_unknown_command(): void
    {
        $tester = new CommandTester(new ShowCommand());

        self::assertSame(Command::INVALID, $tester->execute([
            'command-name' => 'not-a-command',
        ]));

        self::assertStringContainsString('No output schema is published', $tester->getDisplay());
    }
}
