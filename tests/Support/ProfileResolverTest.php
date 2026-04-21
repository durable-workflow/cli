<?php

declare(strict_types=1);

namespace Tests\Support;

use DurableWorkflow\Cli\Support\InvalidOptionException;
use DurableWorkflow\Cli\Support\Profile;
use DurableWorkflow\Cli\Support\ProfileResolver;
use DurableWorkflow\Cli\Support\ProfileStore;
use PHPUnit\Framework\TestCase;

class ProfileResolverTest extends TestCase
{
    private string $tmpConfig = '';

    /** @var list<string> */
    private array $envsToClear = [];

    protected function setUp(): void
    {
        $this->tmpConfig = sys_get_temp_dir().'/dw-cli-profile-resolver-'.bin2hex(random_bytes(8)).'/config.json';
    }

    protected function tearDown(): void
    {
        foreach ($this->envsToClear as $name) {
            putenv($name);
            unset($_ENV[$name]);
        }
        $this->envsToClear = [];

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

    public function test_defaults_when_no_profile_and_no_env(): void
    {
        $resolver = new ProfileResolver(new ProfileStore($this->tmpConfig));

        $resolved = $resolver->resolve(flagEnv: null, flagServer: null, flagNamespace: null, flagToken: null);

        self::assertSame('http://localhost:8080', $resolved->server);
        self::assertSame('default', $resolved->namespace);
        self::assertNull($resolved->token);
        self::assertNull($resolved->profile);
    }

    public function test_env_vars_take_precedence_over_defaults(): void
    {
        $this->setEnv('DURABLE_WORKFLOW_SERVER_URL', 'https://from-env');
        $this->setEnv('DURABLE_WORKFLOW_NAMESPACE', 'env-ns');
        $this->setEnv('DURABLE_WORKFLOW_AUTH_TOKEN', 'env-token');

        $resolver = new ProfileResolver(new ProfileStore($this->tmpConfig));
        $resolved = $resolver->resolve(null, null, null, null);

        self::assertSame('https://from-env', $resolved->server);
        self::assertSame('env-ns', $resolved->namespace);
        self::assertSame('env-token', $resolved->token);
    }

    public function test_flags_take_precedence_over_env_vars(): void
    {
        $this->setEnv('DURABLE_WORKFLOW_SERVER_URL', 'https://from-env');
        $this->setEnv('DURABLE_WORKFLOW_NAMESPACE', 'env-ns');

        $resolver = new ProfileResolver(new ProfileStore($this->tmpConfig));
        $resolved = $resolver->resolve(
            flagEnv: null,
            flagServer: 'https://from-flag',
            flagNamespace: 'flag-ns',
            flagToken: 'flag-token',
        );

        self::assertSame('https://from-flag', $resolved->server);
        self::assertSame('flag-ns', $resolved->namespace);
        self::assertSame('flag-token', $resolved->token);
    }

    public function test_profile_selected_via_flag_supplies_server_and_namespace(): void
    {
        $store = new ProfileStore($this->tmpConfig);
        $store->put(new Profile(
            name: 'prod',
            server: 'https://profile-server',
            namespace: 'profile-ns',
            tlsVerify: false,
        ));

        $resolver = new ProfileResolver($store);
        $resolved = $resolver->resolve(flagEnv: 'prod', flagServer: null, flagNamespace: null, flagToken: null);

        self::assertSame('https://profile-server', $resolved->server);
        self::assertSame('profile-ns', $resolved->namespace);
        self::assertFalse($resolved->tlsVerify);
        self::assertSame('prod', $resolved->profile?->name);
    }

    public function test_hard_fails_on_unknown_flag_env(): void
    {
        $resolver = new ProfileResolver(new ProfileStore($this->tmpConfig));

        try {
            $resolver->resolve(flagEnv: 'prod', flagServer: null, flagNamespace: null, flagToken: null);
            self::fail('Expected InvalidOptionException');
        } catch (InvalidOptionException $e) {
            self::assertStringContainsString('Unknown dw environment [prod]', $e->getMessage());
            self::assertStringContainsString('--env', $e->getMessage());
        }
    }

    public function test_hard_fails_on_unknown_dw_env_variable(): void
    {
        $this->setEnv('DW_ENV', 'ghost');
        $resolver = new ProfileResolver(new ProfileStore($this->tmpConfig));

        try {
            $resolver->resolve(flagEnv: null, flagServer: null, flagNamespace: null, flagToken: null);
            self::fail('Expected InvalidOptionException');
        } catch (InvalidOptionException $e) {
            self::assertStringContainsString('Unknown dw environment [ghost]', $e->getMessage());
            self::assertStringContainsString('DW_ENV', $e->getMessage());
        }
    }

    public function test_hard_fails_on_unknown_current_env_in_config(): void
    {
        $store = new ProfileStore($this->tmpConfig);
        $store->put(new Profile(name: 'dev', server: 'http://localhost:8080'));
        $store->setCurrent('dev');
        // Manually rewrite file to simulate a drifted config pointing at a missing env.
        $raw = json_decode((string) file_get_contents($this->tmpConfig), true);
        $raw['current_env'] = 'vanished';
        file_put_contents($this->tmpConfig, json_encode($raw));

        $resolver = new ProfileResolver(new ProfileStore($this->tmpConfig));

        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessage('Unknown dw environment [vanished]');
        $resolver->resolve(null, null, null, null);
    }

    public function test_flag_env_outranks_dw_env_variable(): void
    {
        $store = new ProfileStore($this->tmpConfig);
        $store->put(new Profile(name: 'flag', server: 'https://flag-server'));
        $store->put(new Profile(name: 'envvar', server: 'https://envvar-server'));
        $this->setEnv('DW_ENV', 'envvar');

        $resolver = new ProfileResolver($store);
        $resolved = $resolver->resolve(flagEnv: 'flag', flagServer: null, flagNamespace: null, flagToken: null);

        self::assertSame('https://flag-server', $resolved->server);
        self::assertSame('flag', $resolved->profile?->name);
    }

    public function test_dw_env_variable_outranks_current_env_in_config(): void
    {
        $store = new ProfileStore($this->tmpConfig);
        $store->put(new Profile(name: 'current', server: 'https://current-server'));
        $store->put(new Profile(name: 'envvar', server: 'https://envvar-server'));
        $store->setCurrent('current');
        $this->setEnv('DW_ENV', 'envvar');

        $resolver = new ProfileResolver(new ProfileStore($this->tmpConfig));
        $resolved = $resolver->resolve(null, null, null, null);

        self::assertSame('https://envvar-server', $resolved->server);
        self::assertSame('envvar', $resolved->profile?->name);
    }

    public function test_empty_flag_env_does_not_trigger_profile_selection(): void
    {
        $resolver = new ProfileResolver(new ProfileStore($this->tmpConfig));
        $resolved = $resolver->resolve(flagEnv: '', flagServer: null, flagNamespace: null, flagToken: null);

        self::assertNull($resolved->profile);
    }

    public function test_env_token_source_resolves_at_invocation_time(): void
    {
        $store = new ProfileStore($this->tmpConfig);
        $store->put(new Profile(
            name: 'prod',
            server: 'https://api.example.com',
            tokenSource: ['type' => Profile::TOKEN_SOURCE_ENV, 'value' => 'DW_RESOLVER_TEST_TOKEN'],
        ));
        $this->setEnv('DW_RESOLVER_TEST_TOKEN', 'resolved-prod-token');

        $resolver = new ProfileResolver($store);
        $resolved = $resolver->resolve(flagEnv: 'prod', flagServer: null, flagNamespace: null, flagToken: null);

        self::assertSame('resolved-prod-token', $resolved->token);
    }

    public function test_flag_token_outranks_profile_token(): void
    {
        $store = new ProfileStore($this->tmpConfig);
        $store->put(new Profile(
            name: 'prod',
            server: 'https://api.example.com',
            tokenSource: ['type' => Profile::TOKEN_SOURCE_LITERAL, 'value' => 'profile-token'],
        ));

        $resolver = new ProfileResolver($store);
        $resolved = $resolver->resolve(flagEnv: 'prod', flagServer: null, flagNamespace: null, flagToken: 'flag-token');

        self::assertSame('flag-token', $resolved->token);
    }
}
