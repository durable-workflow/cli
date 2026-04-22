<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\BaseCommand;
use DurableWorkflow\Cli\Support\ExitCode;
use DurableWorkflow\Cli\Support\OutputMode;
use DurableWorkflow\Cli\Support\Profile;
use DurableWorkflow\Cli\Support\ProfileStore;
use DurableWorkflow\Cli\Support\ServerClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Verifies that BaseCommand's client() helper — the shared choke point
 * that every server-talking command uses — honours the flag > env var
 * > profile > default precedence, and hard-fails on unknown profiles.
 */
class BaseCommandEnvResolutionTest extends TestCase
{
    private string $tmpConfig = '';

    /** @var list<string> */
    private array $envsToClear = [];

    protected function setUp(): void
    {
        $this->tmpConfig = sys_get_temp_dir().'/dw-cli-base-env-'.bin2hex(random_bytes(8)).'/config.json';
    }

    protected function tearDown(): void
    {
        foreach ($this->envsToClear as $name) {
            putenv($name);
            unset($_ENV[$name]);
        }

        if ($this->tmpConfig !== '' && file_exists($this->tmpConfig)) {
            @unlink($this->tmpConfig);
            @rmdir(dirname($this->tmpConfig));
        }
    }

    private function setEnv(string $name, string $value): void
    {
        $this->envsToClear[] = $name;
        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
    }

    public function test_client_uses_profile_server_when_flag_absent(): void
    {
        $store = new ProfileStore($this->tmpConfig);
        $store->put(new Profile(
            name: 'prod',
            server: 'https://profile.example',
            namespace: 'profile-ns',
        ));
        $store->setCurrent('prod');

        $command = $this->probeCommand();
        $command->setProfileStore($store);

        $tester = new CommandTester($command);
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertSame('https://profile.example', $decoded['server']);
        self::assertSame('profile-ns', $decoded['namespace']);
    }

    public function test_flag_env_hard_fails_when_profile_missing(): void
    {
        $store = new ProfileStore($this->tmpConfig);

        $command = $this->probeCommand();
        $command->setProfileStore($store);

        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['--env' => 'missing']);

        self::assertSame(ExitCode::INVALID, $exitCode);
        self::assertStringContainsString('Unknown dw environment [missing]', $tester->getDisplay());
    }

    public function test_flag_server_overrides_profile(): void
    {
        $store = new ProfileStore($this->tmpConfig);
        $store->put(new Profile(
            name: 'prod',
            server: 'https://profile.example',
            namespace: 'profile-ns',
        ));
        $store->setCurrent('prod');

        $command = $this->probeCommand();
        $command->setProfileStore($store);

        $tester = new CommandTester($command);
        $tester->execute(['--server' => 'https://flag.example']);

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertSame('https://flag.example', $decoded['server']);
    }

    public function test_profile_output_mode_becomes_default_when_output_flag_absent(): void
    {
        $store = new ProfileStore($this->tmpConfig);
        $store->put(new Profile(
            name: 'prod',
            server: 'https://profile.example',
            output: OutputMode::JSON,
        ));
        $store->setCurrent('prod');

        $command = $this->outputProbeCommand();
        $command->setProfileStore($store);

        $tester = new CommandTester($command);
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertSame('json', $decoded['mode']);
    }

    public function test_output_flag_overrides_profile_output_mode(): void
    {
        $store = new ProfileStore($this->tmpConfig);
        $store->put(new Profile(
            name: 'prod',
            server: 'https://profile.example',
            output: OutputMode::JSON,
        ));
        $store->setCurrent('prod');

        $command = $this->outputProbeCommand();
        $command->setProfileStore($store);

        $tester = new CommandTester($command);
        self::assertSame(Command::SUCCESS, $tester->execute(['--output' => OutputMode::TABLE]));

        self::assertSame('mode=table'.PHP_EOL, $tester->getDisplay());
    }

    public function test_client_receives_profile_tls_verify_setting(): void
    {
        $store = new ProfileStore($this->tmpConfig);
        $store->put(new Profile(
            name: 'insecure-dev',
            server: 'https://self-signed.example',
            tlsVerify: false,
        ));
        $store->setCurrent('insecure-dev');

        $command = $this->probeCommand();
        $command->setProfileStore($store);

        $tester = new CommandTester($command);
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertFalse($decoded['tls_verify']);
    }

    public function test_tls_verify_env_overrides_profile(): void
    {
        $this->setEnv('DURABLE_WORKFLOW_TLS_VERIFY', 'true');

        $store = new ProfileStore($this->tmpConfig);
        $store->put(new Profile(
            name: 'insecure-dev',
            server: 'https://self-signed.example',
            tlsVerify: false,
        ));
        $store->setCurrent('insecure-dev');

        $command = $this->probeCommand();
        $command->setProfileStore($store);

        $tester = new CommandTester($command);
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertTrue($decoded['tls_verify']);
    }

    public function test_tls_verify_flag_overrides_env(): void
    {
        $this->setEnv('DURABLE_WORKFLOW_TLS_VERIFY', 'true');

        $command = $this->probeCommand();
        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--tls-verify' => 'false',
        ]));

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertFalse($decoded['tls_verify']);
    }

    public function test_dw_env_hard_fails_when_profile_missing(): void
    {
        $this->setEnv('DW_ENV', 'ghost');
        $store = new ProfileStore($this->tmpConfig);

        $command = $this->probeCommand();
        $command->setProfileStore($store);

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        self::assertSame(ExitCode::INVALID, $exitCode);
        self::assertStringContainsString('Unknown dw environment [ghost]', $tester->getDisplay());
    }

    /**
     * Returns an anonymous BaseCommand subclass that prints the resolved
     * server/namespace it would have used, without actually hitting the
     * network. Using the real client() helper is the whole point of the
     * test — a mock would miss the resolver wiring regressions.
     */
    private function probeCommand(): BaseCommand
    {
        return new class extends BaseCommand {
            public function __construct()
            {
                parent::__construct('probe:env');
            }

            protected function configure(): void
            {
                parent::configure();
                $this->setName('probe:env');
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                // client() builds a ServerClient through the resolver; reflect
                // out the base URL + namespace so the test can assert on them
                // without any HTTP.
                $client = $this->client($input);
                [$base, $ns, $tlsVerify] = $this->introspect($client);
                $output->writeln(json_encode([
                    'server' => $base,
                    'namespace' => $ns,
                    'tls_verify' => $tlsVerify,
                ]));

                return Command::SUCCESS;
            }

            /**
             * @return array{0: string, 1: string, 2: bool}
             */
            private function introspect(ServerClient $client): array
            {
                $reflect = new \ReflectionClass($client);
                $base = $reflect->getProperty('baseUrl');
                $ns = $reflect->getProperty('namespace');
                $tlsVerify = $reflect->getProperty('tlsVerify');

                return [
                    (string) $base->getValue($client),
                    (string) $ns->getValue($client),
                    (bool) $tlsVerify->getValue($client),
                ];
            }
        };
    }

    private function outputProbeCommand(): BaseCommand
    {
        return new class extends BaseCommand {
            public function __construct()
            {
                parent::__construct('probe:output');
            }

            protected function configure(): void
            {
                parent::configure();
                $this->setName('probe:output');
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                if ($this->wantsJson($input)) {
                    return $this->renderJson($output, ['mode' => 'json']);
                }

                $output->writeln('mode=table');

                return Command::SUCCESS;
            }
        };
    }
}
