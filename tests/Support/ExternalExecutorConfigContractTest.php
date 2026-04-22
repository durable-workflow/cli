<?php

declare(strict_types=1);

namespace Tests\Support;

use DurableWorkflow\Cli\Support\ExternalExecutorConfigContract;
use PHPUnit\Framework\TestCase;

final class ExternalExecutorConfigContractTest extends TestCase
{
    public function test_published_examples_satisfy_external_executor_auth_contract(): void
    {
        foreach (glob(dirname(__DIR__, 2).'/examples/external-executor/*.json') ?: [] as $path) {
            $config = self::readConfig($path);

            self::assertSame([], ExternalExecutorConfigContract::validate($config), basename($path));
        }
    }

    public function test_rejects_unknown_auth_ref_and_duplicate_mapping_names(): void
    {
        $config = self::validConfig();
        $config['defaults']['auth_ref'] = 'missing';
        $config['mappings'][] = $config['mappings'][0];

        $errors = ExternalExecutorConfigContract::validate($config);

        self::assertContains('defaults.auth_ref references unknown auth_ref [missing]', $errors);
        self::assertContains('duplicate mapping name [billing.charge-card]', $errors);
    }

    public function test_auth_refs_are_typed_redacted_references(): void
    {
        $config = self::validConfig();
        $config['auth_refs'] = [
            'inline-token' => [
                'type' => 'env',
                'env' => 'DW_TOKEN',
                'path' => '/tmp/token',
            ],
            'mtls-secret-key' => [
                'type' => 'mtls',
                'cert' => '/etc/dw/client.crt',
                'key' => 'inline-private-key',
            ],
            'signed' => [
                'type' => 'signed_headers',
                'key_ref' => 'kms://durable-workflow/signing-key',
                'header_allowlist' => [],
            ],
        ];

        $errors = ExternalExecutorConfigContract::validate($config);

        self::assertContains('auth_ref [inline-token] type [env] must not persist path', $errors);
        self::assertContains('auth_ref [mtls-secret-key] requires key_ref', $errors);
        self::assertContains('auth_ref [mtls-secret-key] type [mtls] must not persist key', $errors);
        self::assertContains('auth_ref [signed] requires non-empty header_allowlist', $errors);
    }

    public function test_all_reserved_auth_material_shapes_can_be_declared_by_reference(): void
    {
        $config = self::validConfig();
        $config['auth_refs'] = [
            'profile-auth' => [
                'type' => 'profile',
                'profile' => 'prod',
            ],
            'env-auth' => [
                'type' => 'env',
                'env' => 'DURABLE_WORKFLOW_AUTH_TOKEN',
            ],
            'file-auth' => [
                'type' => 'token_file',
                'path' => '/var/run/secrets/dw-token',
            ],
            'mtls-auth' => [
                'type' => 'mtls',
                'cert' => '/etc/dw/client.crt',
                'key_ref' => 'file:///var/run/secrets/dw-client-key',
            ],
            'signed-auth' => [
                'type' => 'signed_headers',
                'key_ref' => 'kms://durable-workflow/signing-key',
                'header_allowlist' => ['date', 'digest', 'x-durable-workflow-signature'],
            ],
        ];
        $config['defaults']['auth_ref'] = 'signed-auth';

        self::assertSame([], ExternalExecutorConfigContract::validate($config));
    }

    /**
     * @return array<string, mixed>
     */
    private static function validConfig(): array
    {
        return [
            'schema' => ExternalExecutorConfigContract::SCHEMA,
            'version' => ExternalExecutorConfigContract::VERSION,
            'defaults' => [
                'auth_ref' => 'profile-auth',
            ],
            'auth_refs' => [
                'profile-auth' => [
                    'type' => 'profile',
                    'profile' => 'prod',
                ],
            ],
            'carriers' => [
                'process' => [
                    'type' => 'process',
                    'command' => ['php', 'artisan', 'durable:external-handler'],
                    'capabilities' => ['activity_task'],
                ],
            ],
            'mappings' => [
                [
                    'name' => 'billing.charge-card',
                    'kind' => 'activity',
                    'carrier' => 'process',
                    'handler' => 'App\\Durable\\Handlers\\ChargeCard',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function readConfig(string $path): array
    {
        $contents = file_get_contents($path);
        self::assertIsString($contents);

        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
