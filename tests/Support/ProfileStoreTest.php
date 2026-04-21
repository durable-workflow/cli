<?php

declare(strict_types=1);

namespace Tests\Support;

use DurableWorkflow\Cli\Support\InvalidOptionException;
use DurableWorkflow\Cli\Support\Profile;
use DurableWorkflow\Cli\Support\ProfileStore;
use PHPUnit\Framework\TestCase;

class ProfileStoreTest extends TestCase
{
    private string $tmpConfig = '';

    protected function setUp(): void
    {
        $this->tmpConfig = sys_get_temp_dir().'/dw-cli-profile-store-'.bin2hex(random_bytes(8)).'/config.json';
    }

    protected function tearDown(): void
    {
        if ($this->tmpConfig !== '' && file_exists($this->tmpConfig)) {
            @unlink($this->tmpConfig);
            @rmdir(dirname($this->tmpConfig));
        }
    }

    public function test_load_returns_empty_shell_when_config_missing(): void
    {
        $store = new ProfileStore($this->tmpConfig);

        self::assertFalse($store->exists());
        self::assertSame([], $store->all());
        self::assertNull($store->currentEnvName());
    }

    public function test_put_creates_dir_and_writes_restrictive_permissions(): void
    {
        $store = new ProfileStore($this->tmpConfig);

        $store->put(new Profile(
            name: 'dev',
            server: 'http://localhost:8080',
            namespace: 'default',
        ));

        self::assertTrue($store->exists());
        $permissions = fileperms($this->tmpConfig) & 0777;
        self::assertSame(0600, $permissions, 'config file must be 0600 so peers cannot read stored credentials');

        $dirPermissions = fileperms(dirname($this->tmpConfig)) & 0777;
        self::assertSame(0700, $dirPermissions, 'config directory must be 0700');
    }

    public function test_put_and_get_round_trip_preserves_token_source(): void
    {
        $store = new ProfileStore($this->tmpConfig);

        $store->put(new Profile(
            name: 'prod',
            server: 'https://api.example.com',
            namespace: 'orders',
            tokenSource: ['type' => Profile::TOKEN_SOURCE_ENV, 'value' => 'PROD_DW_TOKEN'],
            tlsVerify: true,
            output: 'json',
        ));

        $profile = $store->get('prod');

        self::assertNotNull($profile);
        self::assertSame('prod', $profile->name);
        self::assertSame('https://api.example.com', $profile->server);
        self::assertSame('orders', $profile->namespace);
        self::assertSame(['type' => 'env', 'value' => 'PROD_DW_TOKEN'], $profile->tokenSource);
        self::assertTrue($profile->tlsVerify);
        self::assertSame('json', $profile->output);
    }

    public function test_set_current_requires_existing_profile(): void
    {
        $store = new ProfileStore($this->tmpConfig);

        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessage('Cannot set current env to [missing]');

        $store->setCurrent('missing');
    }

    public function test_set_current_persists_across_instances(): void
    {
        $store = new ProfileStore($this->tmpConfig);
        $store->put(new Profile(name: 'dev', server: 'http://localhost:8080'));
        $store->setCurrent('dev');

        $fresh = new ProfileStore($this->tmpConfig);
        self::assertSame('dev', $fresh->currentEnvName());
    }

    public function test_delete_clears_current_env_when_target_is_current(): void
    {
        $store = new ProfileStore($this->tmpConfig);
        $store->put(new Profile(name: 'dev', server: 'http://localhost:8080'));
        $store->put(new Profile(name: 'staging', server: 'http://localhost:8081'));
        $store->setCurrent('dev');

        $store->delete('dev');

        self::assertNull($store->currentEnvName());
        self::assertArrayNotHasKey('dev', $store->all());
        self::assertArrayHasKey('staging', $store->all());
    }

    public function test_delete_leaves_current_env_alone_when_deleting_other_profile(): void
    {
        $store = new ProfileStore($this->tmpConfig);
        $store->put(new Profile(name: 'dev', server: 'http://localhost:8080'));
        $store->put(new Profile(name: 'staging', server: 'http://localhost:8081'));
        $store->setCurrent('dev');

        $store->delete('staging');

        self::assertSame('dev', $store->currentEnvName());
    }

    public function test_require_profile_lists_available_envs_on_unknown_name(): void
    {
        $store = new ProfileStore($this->tmpConfig);
        $store->put(new Profile(name: 'dev', server: 'http://localhost:8080'));
        $store->put(new Profile(name: 'staging', server: 'http://localhost:8081'));

        try {
            $store->requireProfile('prod', '--env');
            self::fail('Expected InvalidOptionException');
        } catch (InvalidOptionException $e) {
            $msg = $e->getMessage();
            self::assertStringContainsString('Unknown dw environment [prod]', $msg);
            self::assertStringContainsString('--env', $msg);
            self::assertStringContainsString('dev', $msg);
            self::assertStringContainsString('staging', $msg);
        }
    }

    public function test_require_profile_suggests_creating_first_env_when_store_empty(): void
    {
        $store = new ProfileStore($this->tmpConfig);

        try {
            $store->requireProfile('dev', 'DW_ENV');
            self::fail('Expected InvalidOptionException');
        } catch (InvalidOptionException $e) {
            self::assertStringContainsString('No environments are configured', $e->getMessage());
        }
    }

    public function test_profile_describe_redacts_literal_token_by_default(): void
    {
        $profile = new Profile(
            name: 'dev',
            tokenSource: ['type' => Profile::TOKEN_SOURCE_LITERAL, 'value' => 'super-secret'],
        );

        $default = $profile->describe();
        self::assertSame('redacted', $default['token_source']['value']);

        $revealed = $profile->describe(showToken: true);
        self::assertSame('super-secret', $revealed['token_source']['value']);
    }

    public function test_profile_describe_always_shows_env_var_name(): void
    {
        $profile = new Profile(
            name: 'dev',
            tokenSource: ['type' => Profile::TOKEN_SOURCE_ENV, 'value' => 'PROD_DW_TOKEN'],
        );

        $described = $profile->describe();
        self::assertSame('env', $described['token_source']['type']);
        self::assertSame('PROD_DW_TOKEN', $described['token_source']['env']);
        self::assertArrayNotHasKey('value', $described['token_source']);
    }

    public function test_profile_resolve_token_reads_env_var_at_call_time(): void
    {
        $profile = new Profile(
            name: 'dev',
            tokenSource: ['type' => Profile::TOKEN_SOURCE_ENV, 'value' => 'DW_PROFILE_RESOLVE_TEST_TOKEN'],
        );

        putenv('DW_PROFILE_RESOLVE_TEST_TOKEN=resolved-value');
        try {
            self::assertSame('resolved-value', $profile->resolveToken());
        } finally {
            putenv('DW_PROFILE_RESOLVE_TEST_TOKEN');
        }

        self::assertNull($profile->resolveToken(), 'token must become null once the referenced env var is cleared');
    }

    public function test_rejects_invalid_output_mode(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Profile(name: 'dev', output: 'yaml');
    }

    public function test_rejects_invalid_token_source_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Profile(name: 'dev', tokenSource: ['type' => 'magic', 'value' => 'x']);
    }
}
