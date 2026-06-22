<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Application;
use DurableWorkflow\Cli\Support\OutputSchemaRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\ApplicationTester;

class ApplicationSmokeTest extends TestCase
{
    public function test_commands_load_without_shortcut_collisions(): void
    {
        $application = new Application();
        $application->setAutoExit(false);

        $tester = new ApplicationTester($application);

        foreach ([
            'activity:list',
            'activity:describe',
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

    public function test_grouped_activity_commands_map_to_activity_visibility_commands(): void
    {
        foreach (['activity', 'activities'] as $group) {
            foreach (['list', 'describe'] as $verb) {
                $application = new Application();
                $application->setAutoExit(false);
                $output = new BufferedOutput();

                $exit = $application->run(new ArgvInput([
                    'dw',
                    $group,
                    $verb,
                    '--help',
                ]), $output);

                self::assertSame(0, $exit, "{$group} {$verb} should resolve to activity:{$verb}");
                self::assertStringContainsString('activity:'.$verb, $output->fetch());
            }
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

    public function test_plural_grouped_workflow_list_command_maps_to_visibility_list_command(): void
    {
        $application = new Application();
        $application->setAutoExit(false);
        $output = new BufferedOutput();

        $exit = $application->run(new ArgvInput([
            'dw',
            'workflows',
            'list',
            '--help',
        ]), $output);

        self::assertSame(0, $exit);
        self::assertStringContainsString('workflow:list', $output->fetch());
    }

    public function test_plural_grouped_search_attribute_commands_map_to_definition_commands(): void
    {
        foreach (['list', 'create', 'delete'] as $verb) {
            $application = new Application();
            $application->setAutoExit(false);
            $output = new BufferedOutput();

            $exit = $application->run(new ArgvInput([
                'dw',
                'search-attributes',
                $verb,
                '--help',
            ]), $output);

            self::assertSame(0, $exit);
            self::assertStringContainsString('search-attribute:'.$verb, $output->fetch());
        }
    }

    public function test_grouped_schedule_commands_map_to_schedule_lifecycle_commands(): void
    {
        foreach (['schedule', 'schedules'] as $group) {
            foreach (['create', 'list', 'describe', 'pause', 'resume', 'trigger', 'delete', 'update', 'backfill', 'history'] as $verb) {
                $application = new Application();
                $application->setAutoExit(false);
                $output = new BufferedOutput();

                $exit = $application->run(new ArgvInput([
                    'dw',
                    $group,
                    $verb,
                    '--help',
                ]), $output);

                self::assertSame(0, $exit, "{$group} {$verb} should resolve to schedule:{$verb}");
                self::assertStringContainsString('schedule:'.$verb, $output->fetch());
            }
        }
    }

    public function test_grouped_schedule_commands_preserve_connection_options_before_group(): void
    {
        $application = new Application();
        $application->setAutoExit(false);
        $output = new BufferedOutput();

        $exit = $application->run(new ArgvInput([
            'dw',
            '--server',
            'http://127.0.0.1:8080',
            '--namespace',
            'orders',
            '--token',
            'secret-token',
            'schedules',
            'list',
            '--help',
        ]), $output);

        self::assertSame(0, $exit);
        self::assertStringContainsString('schedule:list', $output->fetch());
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
