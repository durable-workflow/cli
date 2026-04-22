<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\DoctorCommand;
use DurableWorkflow\Cli\Support\ControlPlaneRequestContract;
use DurableWorkflow\Cli\Support\ExitCode;
use DurableWorkflow\Cli\Support\NetworkException;
use DurableWorkflow\Cli\Support\Profile;
use DurableWorkflow\Cli\Support\ProfileStore;
use DurableWorkflow\Cli\Support\ServerClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class DoctorCommandTest extends TestCase
{
    private string $tmpConfig = '';

    protected function setUp(): void
    {
        $this->tmpConfig = sys_get_temp_dir().'/dw-cli-doctor-'.bin2hex(random_bytes(8)).'/config.json';
        putenv('DW_CLI_VERSION=0.1.5');
    }

    protected function tearDown(): void
    {
        putenv('DW_CLI_VERSION');
        putenv('DOCTOR_DW_TOKEN');
        putenv('DURABLE_WORKFLOW_TLS_VERIFY');
        unset($_ENV['DOCTOR_DW_TOKEN']);
        unset($_ENV['DURABLE_WORKFLOW_TLS_VERIFY']);

        if ($this->tmpConfig !== '' && file_exists($this->tmpConfig)) {
            @unlink($this->tmpConfig);
            @rmdir(dirname($this->tmpConfig));
        }
    }

    public function test_doctor_outputs_json_connection_and_cluster_info(): void
    {
        $command = new DoctorCommand();
        $command->setServerClient(new DoctorFakeClient([
            'server_id' => 'server-1',
            'version' => '0.1.0',
            'default_namespace' => 'default',
            'control_plane' => [
                'version' => '2',
                'header' => 'X-Durable-Workflow-Control-Plane-Version',
                'request_contract' => [
                    'schema' => ControlPlaneRequestContract::SCHEMA,
                    'version' => ControlPlaneRequestContract::VERSION,
                    'operations' => [],
                ],
            ],
            'worker_protocol' => [
                'version' => '1.0',
                'server_capabilities' => [
                    'workflow_task_poll_request_idempotency' => true,
                ],
            ],
        ]));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--server' => 'https://server.example',
            '--namespace' => 'orders',
            '--token' => 'secret-token',
            '--output' => 'json',
        ]));

        $decoded = json_decode(trim($tester->getDisplay()), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('https://server.example', $decoded['connection']['server']);
        self::assertSame('orders', $decoded['connection']['namespace']);
        self::assertTrue($decoded['connection']['token']['present']);
        self::assertSame('flag', $decoded['connection']['token']['source']);
        self::assertTrue($decoded['connection']['tls']['verify']);
        self::assertSame('flag', $decoded['connection']['effective_config']['server']['source']);
        self::assertSame('flag', $decoded['connection']['effective_config']['namespace']['source']);
        self::assertSame('flag', $decoded['connection']['effective_config']['auth']['source']);
        self::assertSame('redacted', $decoded['connection']['effective_config']['auth']['value']);
        self::assertStringNotContainsString('secret-token', $tester->getDisplay());
        self::assertTrue($decoded['server']['reachable']);
        self::assertSame('0.1.0', $decoded['server']['version']);
        self::assertSame('2', $decoded['server']['control_plane']['version']);
        self::assertSame('1.0', $decoded['server']['worker_protocol']['version']);
        self::assertSame('server-1', $decoded['server']['cluster_info']['server_id']);
        self::assertSame([], $decoded['warnings']);
        self::assertSame('doctor.clean', $decoded['recommendations'][0]['id']);
    }

    public function test_doctor_does_not_warn_on_compatible_protocols_when_server_app_version_differs(): void
    {
        $command = new DoctorCommand();
        $command->setServerClient(new DoctorFakeClient([
            'server_id' => 'server-1',
            'version' => '9.8.7',
            'control_plane' => [
                'version' => '2',
                'request_contract' => [
                    'schema' => ControlPlaneRequestContract::SCHEMA,
                    'version' => ControlPlaneRequestContract::VERSION,
                    'operations' => [],
                ],
            ],
            'worker_protocol' => [
                'version' => '1.0',
            ],
            'client_compatibility' => [
                'authority' => 'protocol_manifests',
                'top_level_version_role' => 'informational',
                'clients' => [
                    'cli' => [
                        'supported_versions' => '0.1.x',
                    ],
                ],
            ],
        ]));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--token' => 'secret-token',
            '--output' => 'json',
        ]));

        $decoded = json_decode(trim($tester->getDisplay()), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('9.8.7', $decoded['server']['version']);
        self::assertSame([], $decoded['warnings']);
    }

    public function test_doctor_warns_from_client_compatibility_metadata(): void
    {
        $command = new DoctorCommand();
        $command->setServerClient(new DoctorFakeClient([
            'version' => '0.1.0',
            'control_plane' => [
                'version' => '2',
                'request_contract' => [
                    'schema' => ControlPlaneRequestContract::SCHEMA,
                    'version' => ControlPlaneRequestContract::VERSION,
                    'operations' => [],
                ],
            ],
            'worker_protocol' => [
                'version' => '1.0',
            ],
            'client_compatibility' => [
                'authority' => 'protocol_manifests',
                'clients' => [
                    'cli' => [
                        'supported_versions' => '0.2.x',
                    ],
                ],
            ],
        ]));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--token' => 'secret-token',
            '--output' => 'json',
        ]));

        $decoded = json_decode(trim($tester->getDisplay()), true, 512, JSON_THROW_ON_ERROR);

        self::assertCount(1, $decoded['warnings']);
        self::assertStringContainsString('server-advertised cli supported_versions [0.2.x]', $decoded['warnings'][0]);
        self::assertSame('compatibility.warning', $decoded['recommendations'][0]['id']);
        self::assertSame('dw server:info --output=json', $decoded['recommendations'][0]['command']);
    }

    public function test_doctor_reports_profile_token_source_and_tls_state(): void
    {
        putenv('DOCTOR_DW_TOKEN=profile-secret');
        $_ENV['DOCTOR_DW_TOKEN'] = 'profile-secret';

        $store = new ProfileStore($this->tmpConfig);
        $store->put(new Profile(
            name: 'prod',
            server: 'https://profile.example',
            namespace: 'tenant-a',
            tokenSource: ['type' => Profile::TOKEN_SOURCE_ENV, 'value' => 'DOCTOR_DW_TOKEN'],
            tlsVerify: false,
        ));

        $command = new DoctorCommand();
        $command->setProfileStore($store);
        $command->setServerClient(new DoctorFakeClient([
            'version' => '0.1.0',
            'control_plane' => ['version' => '2'],
            'worker_protocol' => ['version' => '1.0'],
        ]));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--env' => 'prod',
            '--output' => 'json',
        ]));

        $decoded = json_decode(trim($tester->getDisplay()), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('https://profile.example', $decoded['connection']['server']);
        self::assertSame('tenant-a', $decoded['connection']['namespace']);
        self::assertSame('prod', $decoded['connection']['profile']['name']);
        self::assertSame('flag', $decoded['connection']['profile']['source']);
        self::assertFalse($decoded['connection']['tls']['verify']);
        self::assertSame('profile', $decoded['connection']['tls']['source']);
        self::assertTrue($decoded['connection']['token']['present']);
        self::assertSame('profile_env', $decoded['connection']['token']['source']);
        self::assertSame('DOCTOR_DW_TOKEN', $decoded['connection']['token']['env']);
        self::assertSame('profile', $decoded['connection']['effective_config']['server']['source']);
        self::assertSame('profile_env', $decoded['connection']['effective_config']['auth']['source']);
        self::assertSame('DOCTOR_DW_TOKEN', $decoded['connection']['effective_config']['auth']['env']);
        self::assertSame('redacted', $decoded['connection']['effective_config']['auth']['value']);
        self::assertSame('profile', $decoded['connection']['effective_config']['tls']['source']);
        self::assertStringNotContainsString('profile-secret', $tester->getDisplay());
        self::assertSame('tls.verification_disabled', $decoded['recommendations'][0]['id']);
        self::assertSame('dw env:set <name> --tls-verify=true', $decoded['recommendations'][0]['command']);
    }

    public function test_doctor_reports_tls_flag_precedence_in_effective_config(): void
    {
        putenv('DURABLE_WORKFLOW_TLS_VERIFY=false');
        $_ENV['DURABLE_WORKFLOW_TLS_VERIFY'] = 'false';

        $store = new ProfileStore($this->tmpConfig);
        $store->put(new Profile(
            name: 'prod',
            server: 'https://profile.example',
            tlsVerify: false,
        ));

        $command = new DoctorCommand();
        $command->setProfileStore($store);
        $command->setServerClient(new DoctorFakeClient([
            'version' => '0.1.0',
            'control_plane' => ['version' => '2'],
            'worker_protocol' => ['version' => '1.0'],
        ]));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--env' => 'prod',
            '--tls-verify' => 'true',
            '--output' => 'json',
        ]));

        $decoded = json_decode(trim($tester->getDisplay()), true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($decoded['connection']['tls']['verify']);
        self::assertSame('flag', $decoded['connection']['tls']['source']);
        self::assertTrue($decoded['connection']['effective_config']['tls']['verify']);
        self::assertSame('flag', $decoded['connection']['effective_config']['tls']['source']);
    }

    public function test_doctor_returns_network_exit_but_keeps_diagnostic_json(): void
    {
        $command = new DoctorCommand();
        $command->setServerClient(new DoctorFailingClient());

        $tester = new CommandTester($command);

        self::assertSame(ExitCode::NETWORK, $tester->execute([
            '--server' => 'http://unreachable:9999',
            '--output' => 'json',
        ]));

        $decoded = json_decode(trim($tester->getDisplay()), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('http://unreachable:9999', $decoded['connection']['server']);
        self::assertFalse($decoded['server']['reachable']);
        self::assertSame('Connection refused', $decoded['server']['error']);
        self::assertSame('server.unreachable', $decoded['recommendations'][0]['id']);
        self::assertSame('auth.token_missing', $decoded['recommendations'][1]['id']);
    }

    public function test_doctor_human_output_includes_next_steps_for_unreachable_server(): void
    {
        $command = new DoctorCommand();
        $command->setServerClient(new DoctorFailingClient());

        $tester = new CommandTester($command);

        self::assertSame(ExitCode::NETWORK, $tester->execute([
            '--server' => 'http://unreachable:9999',
        ]));

        $display = $tester->getDisplay();

        self::assertStringContainsString('Next steps:', $display);
        self::assertStringContainsString('Check the server URL, DNS, port, and TLS settings', $display);
        self::assertStringContainsString('Try: dw doctor --server=http://unreachable:9999 --output=json', $display);
    }
}

class DoctorFakeClient extends ServerClient
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        private readonly array $payload,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->payload;
    }
}

class DoctorFailingClient extends ServerClient
{
    public function __construct() {}

    public function get(string $path, array $query = []): array
    {
        throw new NetworkException('Connection refused');
    }
}
