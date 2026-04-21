<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\EnvCommand\DeleteCommand;
use DurableWorkflow\Cli\Commands\EnvCommand\ListCommand;
use DurableWorkflow\Cli\Commands\EnvCommand\SetCommand;
use DurableWorkflow\Cli\Commands\EnvCommand\ShowCommand;
use DurableWorkflow\Cli\Commands\EnvCommand\UseCommand;
use DurableWorkflow\Cli\Support\ExitCode;
use DurableWorkflow\Cli\Support\Profile;
use DurableWorkflow\Cli\Support\ProfileStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class EnvCommandTest extends TestCase
{
    private string $tmpConfig = '';

    protected function setUp(): void
    {
        $this->tmpConfig = sys_get_temp_dir().'/dw-cli-env-'.bin2hex(random_bytes(8)).'/config.json';
    }

    protected function tearDown(): void
    {
        if ($this->tmpConfig !== '' && file_exists($this->tmpConfig)) {
            @unlink($this->tmpConfig);
            @rmdir(dirname($this->tmpConfig));
        }
    }

    private function store(): ProfileStore
    {
        return new ProfileStore($this->tmpConfig);
    }

    private function bind(Command $command): CommandTester
    {
        /** @phpstan-ignore-next-line */
        $command->setProfileStore($this->store());

        return new CommandTester($command);
    }

    public function test_set_creates_profile_and_writes_config_file(): void
    {
        $command = new SetCommand();
        $tester = $this->bind($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'name' => 'dev',
            '--server' => 'http://localhost:8080',
            '--namespace' => 'default',
        ]));

        $stored = $this->store()->get('dev');
        self::assertNotNull($stored);
        self::assertSame('http://localhost:8080', $stored->server);
        self::assertSame('default', $stored->namespace);
        self::assertStringContainsString('Saved dw env [dev]', $tester->getDisplay());
    }

    public function test_set_with_make_default_also_sets_current_env(): void
    {
        $command = new SetCommand();
        $tester = $this->bind($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'name' => 'dev',
            '--server' => 'http://localhost:8080',
            '--make-default' => true,
        ]));

        self::assertSame('dev', $this->store()->currentEnvName());
    }

    public function test_set_rejects_conflicting_token_and_token_env(): void
    {
        $command = new SetCommand();
        $tester = $this->bind($command);

        $exitCode = $tester->execute([
            'name' => 'prod',
            '--server' => 'https://api.example.com',
            '--token' => 'literal-value',
            '--token-env' => 'PROD_TOKEN',
        ]);

        self::assertSame(ExitCode::INVALID, $exitCode);
        self::assertStringContainsString(
            'Pass either --token or --token-env, not both',
            $tester->getDisplay(),
        );
    }

    public function test_set_preserves_existing_fields_when_partially_updating(): void
    {
        $this->store()->put(new Profile(
            name: 'dev',
            server: 'http://localhost:8080',
            namespace: 'original-ns',
            tlsVerify: true,
        ));

        $command = new SetCommand();
        $tester = $this->bind($command);
        $tester->execute([
            'name' => 'dev',
            '--namespace' => 'updated-ns',
        ]);

        $stored = $this->store()->get('dev');
        self::assertSame('http://localhost:8080', $stored?->server);
        self::assertSame('updated-ns', $stored?->namespace);
    }

    public function test_set_rejects_invalid_token_env_name(): void
    {
        $command = new SetCommand();
        $tester = $this->bind($command);

        $exitCode = $tester->execute([
            'name' => 'dev',
            '--server' => 'http://localhost:8080',
            '--token-env' => 'lowercase-bad',
        ]);

        self::assertSame(ExitCode::INVALID, $exitCode);
        self::assertStringContainsString('Invalid --token-env', $tester->getDisplay());
    }

    public function test_set_rejects_invalid_profile_name(): void
    {
        $command = new SetCommand();
        $tester = $this->bind($command);

        $exitCode = $tester->execute([
            'name' => '!bad name',
            '--server' => 'http://localhost:8080',
        ]);

        self::assertSame(ExitCode::INVALID, $exitCode);
    }

    public function test_list_marks_current_env(): void
    {
        $this->store()->put(new Profile(name: 'dev', server: 'http://localhost:8080'));
        $this->store()->put(new Profile(name: 'prod', server: 'https://api.example.com'));
        $this->store()->setCurrent('prod');

        $command = new ListCommand();
        $tester = $this->bind($command);
        $tester->execute(['--json' => true]);

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertSame('prod', $decoded['current_env']);

        $byName = [];
        foreach ($decoded['envs'] as $env) {
            $byName[$env['name']] = $env;
        }
        self::assertTrue($byName['prod']['current']);
        self::assertFalse($byName['dev']['current']);
    }

    public function test_list_jsonl_streams_one_profile_per_line(): void
    {
        $this->store()->put(new Profile(name: 'dev', server: 'http://localhost:8080'));
        $this->store()->put(new Profile(name: 'prod', server: 'https://api.example.com', namespace: 'orders'));
        $this->store()->setCurrent('prod');

        $command = new ListCommand();
        $tester = $this->bind($command);

        self::assertSame(Command::SUCCESS, $tester->execute(['--output' => 'jsonl']));

        $lines = array_values(array_filter(
            explode("\n", $tester->getDisplay()),
            static fn (string $line): bool => $line !== '',
        ));

        self::assertCount(2, $lines);

        $decoded = array_map(
            static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            $lines,
        );

        self::assertSame(['dev', 'prod'], array_column($decoded, 'name'));
        self::assertSame([false, true], array_column($decoded, 'current'));
        self::assertArrayNotHasKey('envs', $decoded[0]);
        self::assertArrayNotHasKey('current_env', $decoded[0]);
    }

    public function test_list_jsonl_empty_store_outputs_empty_stream(): void
    {
        $command = new ListCommand();
        $tester = $this->bind($command);

        self::assertSame(Command::SUCCESS, $tester->execute(['--output' => 'jsonl']));
        self::assertSame('', trim($tester->getDisplay()));
    }

    public function test_list_redacts_literal_token_by_default(): void
    {
        $this->store()->put(new Profile(
            name: 'dev',
            server: 'http://localhost:8080',
            tokenSource: ['type' => Profile::TOKEN_SOURCE_LITERAL, 'value' => 'super-secret-token'],
        ));

        $command = new ListCommand();
        $tester = $this->bind($command);
        $tester->execute(['--json' => true]);

        $decoded = json_decode($tester->getDisplay(), true);
        $tokenSource = $decoded['envs'][0]['token_source'];

        self::assertSame('literal', $tokenSource['type']);
        self::assertSame('redacted', $tokenSource['value']);
        self::assertStringNotContainsString('super-secret-token', $tester->getDisplay());
    }

    public function test_list_reveals_literal_token_with_show_token_flag(): void
    {
        $this->store()->put(new Profile(
            name: 'dev',
            server: 'http://localhost:8080',
            tokenSource: ['type' => Profile::TOKEN_SOURCE_LITERAL, 'value' => 'super-secret-token'],
        ));

        $command = new ListCommand();
        $tester = $this->bind($command);
        $tester->execute(['--json' => true, '--show-token' => true]);

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertSame('super-secret-token', $decoded['envs'][0]['token_source']['value']);
    }

    public function test_list_empty_store_prints_guidance(): void
    {
        $command = new ListCommand();
        $tester = $this->bind($command);
        $tester->execute([]);

        self::assertStringContainsString('No dw environments configured', $tester->getDisplay());
    }

    public function test_use_hard_fails_on_unknown_profile(): void
    {
        $this->store()->put(new Profile(name: 'dev', server: 'http://localhost:8080'));

        $command = new UseCommand();
        $tester = $this->bind($command);

        $exitCode = $tester->execute(['name' => 'missing']);

        self::assertSame(ExitCode::INVALID, $exitCode);
        self::assertStringContainsString('missing', $tester->getDisplay());
        // current_env must NOT have been mutated
        self::assertNull($this->store()->currentEnvName());
    }

    public function test_use_sets_current_env(): void
    {
        $this->store()->put(new Profile(name: 'dev', server: 'http://localhost:8080'));

        $command = new UseCommand();
        $tester = $this->bind($command);
        $tester->execute(['name' => 'dev']);

        self::assertSame('dev', $this->store()->currentEnvName());
    }

    public function test_show_defaults_to_current_env(): void
    {
        $this->store()->put(new Profile(
            name: 'dev',
            server: 'http://localhost:8080',
            namespace: 'default',
        ));
        $this->store()->setCurrent('dev');

        $command = new ShowCommand();
        $tester = $this->bind($command);
        $tester->execute(['--json' => true]);

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertSame('dev', $decoded['name']);
        self::assertTrue($decoded['current']);
    }

    public function test_show_without_name_and_no_current_fails(): void
    {
        $command = new ShowCommand();
        $tester = $this->bind($command);

        $exitCode = $tester->execute([]);

        self::assertSame(ExitCode::INVALID, $exitCode);
        self::assertStringContainsString('No default dw env is set', $tester->getDisplay());
    }

    public function test_show_unknown_name_hard_fails(): void
    {
        $command = new ShowCommand();
        $tester = $this->bind($command);

        $exitCode = $tester->execute(['name' => 'ghost']);

        self::assertSame(ExitCode::INVALID, $exitCode);
        self::assertStringContainsString('Unknown dw environment [ghost]', $tester->getDisplay());
    }

    public function test_delete_removes_profile_and_clears_current_when_appropriate(): void
    {
        $this->store()->put(new Profile(name: 'dev', server: 'http://localhost:8080'));
        $this->store()->put(new Profile(name: 'prod', server: 'https://api.example.com'));
        $this->store()->setCurrent('dev');

        $command = new DeleteCommand();
        $tester = $this->bind($command);
        $tester->execute(['name' => 'dev', '--json' => true]);

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertTrue($decoded['deleted']);
        self::assertTrue($decoded['cleared_current_env']);

        self::assertNull($this->store()->currentEnvName());
        self::assertArrayNotHasKey('dev', $this->store()->all());
        self::assertArrayHasKey('prod', $this->store()->all());
    }

    public function test_delete_keeps_current_env_when_deleting_a_different_profile(): void
    {
        $this->store()->put(new Profile(name: 'dev', server: 'http://localhost:8080'));
        $this->store()->put(new Profile(name: 'prod', server: 'https://api.example.com'));
        $this->store()->setCurrent('prod');

        $command = new DeleteCommand();
        $tester = $this->bind($command);
        $tester->execute(['name' => 'dev', '--json' => true]);

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertFalse($decoded['cleared_current_env']);
        self::assertSame('prod', $this->store()->currentEnvName());
    }

    public function test_delete_unknown_profile_hard_fails(): void
    {
        $command = new DeleteCommand();
        $tester = $this->bind($command);

        $exitCode = $tester->execute(['name' => 'ghost']);

        self::assertSame(ExitCode::INVALID, $exitCode);
    }

    public function test_set_clear_token_source_with_empty_token_env(): void
    {
        $this->store()->put(new Profile(
            name: 'dev',
            server: 'http://localhost:8080',
            tokenSource: ['type' => Profile::TOKEN_SOURCE_ENV, 'value' => 'OLD_TOKEN'],
        ));

        $command = new SetCommand();
        $tester = $this->bind($command);
        $tester->execute([
            'name' => 'dev',
            '--token-env' => '',
        ]);

        self::assertNull($this->store()->get('dev')?->tokenSource);
    }
}
