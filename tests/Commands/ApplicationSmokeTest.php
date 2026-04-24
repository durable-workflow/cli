<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Application;
use DurableWorkflow\Cli\Support\OutputSchemaRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\ApplicationTester;

class ApplicationSmokeTest extends TestCase
{
    public function test_commands_load_without_shortcut_collisions(): void
    {
        $application = new Application();
        $application->setAutoExit(false);

        $tester = new ApplicationTester($application);

        foreach ([
            'workflow:start',
            'workflow:list',
            'workflow:list-runs',
            'workflow:show-run',
            'schedule:create',
            'upgrade',
        ] as $command) {
            self::assertSame(0, $tester->run([
                'command' => $command,
                '--help' => true,
            ]));
        }
    }

    public function test_version_output_reports_build_identity(): void
    {
        $application = new Application();
        $application->setAutoExit(false);

        $tester = new ApplicationTester($application);

        self::assertSame(0, $tester->run([
            '--version' => true,
        ]));

        self::assertMatchesRegularExpression(
            '/^dw \S+ \(commit [^)]+, built [^)]+\)/',
            trim($tester->getDisplay()),
        );
    }

    public function test_every_command_has_description_and_help_with_operator_examples(): void
    {
        $application = new Application();
        $application->setAutoExit(false);

        foreach ($application->all() as $name => $command) {
            if (in_array($name, ['help', 'list', '_complete', 'completion'], true)) {
                continue;
            }

            self::assertNotSame(
                '',
                $command->getDescription(),
                "Command {$name} is missing setDescription() text.",
            );

            $help = $command->getHelp();
            self::assertNotSame(
                '',
                $help,
                "Command {$name} is missing setHelp() text.",
            );

            self::assertStringContainsString(
                'dw ',
                $help,
                "Command {$name} help is missing at least one 'dw ...' example.",
            );

            self::assertGreaterThanOrEqual(
                2,
                substr_count($help, 'dw '),
                "Command {$name} help should include at least two concrete 'dw ...' examples.",
            );
        }
    }

    public function test_every_json_output_command_has_a_published_schema(): void
    {
        $application = new Application();
        $application->setAutoExit(false);

        foreach ($application->all() as $name => $command) {
            if (in_array($name, ['help', 'list', '_complete', 'completion'], true)) {
                continue;
            }

            if (! $command->getDefinition()->hasOption('json')) {
                continue;
            }

            self::assertTrue(
                OutputSchemaRegistry::hasCommand($name),
                "Command {$name} supports --json but is missing from schemas/output/manifest.json.",
            );
        }
    }

    public function test_schema_manifest_references_existing_commands_and_valid_schema_files(): void
    {
        $application = new Application();
        $application->setAutoExit(false);
        $commands = $application->all();

        foreach (OutputSchemaRegistry::entries() as $entry) {
            $name = (string) $entry['command'];

            self::assertArrayHasKey(
                $name,
                $commands,
                "Output schema manifest references unknown command {$name}.",
            );

            if (($entry['output'] ?? null) === '--json') {
                self::assertTrue(
                    $commands[$name]->getDefinition()->hasOption('json'),
                    "Manifest entry {$name} declares --json output but the command has no --json option.",
                );
            }

            $schema = OutputSchemaRegistry::schema($name);

            self::assertSame(
                $entry['schema_id'] ?? null,
                $schema['$id'] ?? null,
                "Manifest schema_id for {$name} does not match the schema file.",
            );
            self::assertSame('object', $schema['type'] ?? null, "Schema for {$name} must describe a JSON object.");
        }
    }
}
