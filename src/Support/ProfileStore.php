<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Support;

/**
 * On-disk store for named dw environment profiles.
 *
 * File format is JSON (not TOML) — stdlib support is free and the shape
 * is simple enough that a dedicated config format is overkill:
 *
 *   {
 *     "schema": "durable-workflow.cli.config",
 *     "version": 1,
 *     "current_env": "dev",
 *     "envs": {
 *       "dev":  { "server": "http://localhost:8080", "namespace": "default", "tls_verify": true },
 *       "prod": { "server": "https://api.example.com", "namespace": "orders",
 *                 "token_source": { "type": "env", "value": "PROD_DW_TOKEN" }, "tls_verify": true,
 *                 "output": "json" }
 *     }
 *   }
 *
 * Path resolution:
 *   1. $DW_CONFIG_HOME (override — used by tests and anyone running multiple profiles per host).
 *   2. $XDG_CONFIG_HOME/dw/config.json (Linux/macOS default when set).
 *   3. $HOME/.config/dw/config.json (fallback).
 *   4. $APPDATA/dw/config.json (Windows).
 *
 * The store never reads or writes anything outside that path, and the
 * config directory is created with 0700 / the file with 0600 when we
 * create them so token material does not leak to other users on the host.
 */
final class ProfileStore
{
    public const SCHEMA = 'durable-workflow.cli.config';

    public const VERSION = 1;

    private const DIR_MODE = 0700;

    private const FILE_MODE = 0600;

    /**
     * @param  string|null  $configPath  Explicit path override for tests and
     *                                   advanced users. When null the store
     *                                   resolves the default location lazily.
     */
    public function __construct(private ?string $configPath = null) {}

    public function path(): string
    {
        if ($this->configPath !== null) {
            return $this->configPath;
        }

        $this->configPath = self::defaultPath();

        return $this->configPath;
    }

    public function exists(): bool
    {
        return is_file($this->path());
    }

    /**
     * Load the raw config document. Returns an empty shell when the
     * file does not exist so callers can compose writes without a
     * conditional on every path.
     *
     * @return array{schema: string, version: int, current_env: string|null, envs: array<string, Profile>}
     */
    public function load(): array
    {
        if (! $this->exists()) {
            return [
                'schema' => self::SCHEMA,
                'version' => self::VERSION,
                'current_env' => null,
                'envs' => [],
            ];
        }

        $contents = file_get_contents($this->path());
        if ($contents === false) {
            throw new \RuntimeException(sprintf('Unable to read dw config file [%s].', $this->path()));
        }

        try {
            $decoded = json_decode($contents, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException(sprintf(
                'dw config file [%s] is not valid JSON: %s',
                $this->path(),
                $exception->getMessage(),
            ), previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new \RuntimeException(sprintf('dw config file [%s] must decode to an object.', $this->path()));
        }

        $envsRaw = $decoded['envs'] ?? [];
        if (! is_array($envsRaw)) {
            throw new \RuntimeException(sprintf('dw config file [%s] has a non-object envs value.', $this->path()));
        }

        $envs = [];
        foreach ($envsRaw as $name => $entry) {
            if (! is_string($name) || ! is_array($entry)) {
                continue;
            }
            $envs[$name] = Profile::fromArray($name, $entry);
        }

        $currentEnv = $decoded['current_env'] ?? null;
        if ($currentEnv !== null && ! is_string($currentEnv)) {
            $currentEnv = null;
        }

        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'current_env' => $currentEnv,
            'envs' => $envs,
        ];
    }

    /**
     * @return array<string, Profile>
     */
    public function all(): array
    {
        return $this->load()['envs'];
    }

    public function get(string $name): ?Profile
    {
        return $this->load()['envs'][$name] ?? null;
    }

    public function requireProfile(string $name, string $source): Profile
    {
        $profile = $this->get($name);
        if ($profile !== null) {
            return $profile;
        }

        $available = array_keys($this->all());
        throw new InvalidOptionException(sprintf(
            'Unknown dw environment [%s] (requested via %s).%s',
            $name,
            $source,
            $available === []
                ? ' No environments are configured — run `dw env:set <name> --server=...`.'
                : ' Available environments: ['.implode(', ', $available).']. Run `dw env:set '.$name.' --server=...` to create it.',
        ));
    }

    public function currentEnvName(): ?string
    {
        return $this->load()['current_env'];
    }

    public function put(Profile $profile): void
    {
        $config = $this->load();
        $config['envs'][$profile->name] = $profile;
        $this->write($config);
    }

    public function setCurrent(string $name): Profile
    {
        $config = $this->load();
        if (! isset($config['envs'][$name])) {
            $available = array_keys($config['envs']);
            throw new InvalidOptionException(sprintf(
                'Cannot set current env to [%s]: profile does not exist.%s',
                $name,
                $available === []
                    ? ' No environments are configured.'
                    : ' Available environments: ['.implode(', ', $available).'].',
            ));
        }

        $config['current_env'] = $name;
        $this->write($config);

        return $config['envs'][$name];
    }

    public function clearCurrent(): void
    {
        $config = $this->load();
        $config['current_env'] = null;
        $this->write($config);
    }

    public function delete(string $name): Profile
    {
        $config = $this->load();
        if (! isset($config['envs'][$name])) {
            throw new InvalidOptionException(sprintf('Cannot delete env [%s]: profile does not exist.', $name));
        }

        $removed = $config['envs'][$name];
        unset($config['envs'][$name]);

        if ($config['current_env'] === $name) {
            $config['current_env'] = null;
        }

        $this->write($config);

        return $removed;
    }

    /**
     * @param  array{schema: string, version: int, current_env: string|null, envs: array<string, Profile>}  $config
     */
    private function write(array $config): void
    {
        $path = $this->path();
        $dir = dirname($path);

        if (! is_dir($dir)) {
            if (! @mkdir($dir, self::DIR_MODE, true) && ! is_dir($dir)) {
                throw new \RuntimeException(sprintf('Unable to create dw config directory [%s].', $dir));
            }
            @chmod($dir, self::DIR_MODE);
        }

        $envs = [];
        foreach ($config['envs'] as $name => $profile) {
            $envs[$name] = $profile->toArray();
        }
        ksort($envs);

        $document = [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'current_env' => $config['current_env'],
            'envs' => $envs,
        ];

        $encoded = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $tempPath = $path.'.tmp';
        if (@file_put_contents($tempPath, $encoded.PHP_EOL) === false) {
            throw new \RuntimeException(sprintf('Unable to write dw config file [%s].', $tempPath));
        }
        @chmod($tempPath, self::FILE_MODE);

        if (! @rename($tempPath, $path)) {
            @unlink($tempPath);
            throw new \RuntimeException(sprintf('Unable to commit dw config file [%s].', $path));
        }
    }

    public static function defaultPath(): string
    {
        $override = self::envString('DW_CONFIG_HOME');
        if ($override !== null) {
            return rtrim($override, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'config.json';
        }

        $xdg = self::envString('XDG_CONFIG_HOME');
        if ($xdg !== null) {
            return rtrim($xdg, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'dw'.DIRECTORY_SEPARATOR.'config.json';
        }

        $appData = self::envString('APPDATA');
        if ($appData !== null && DIRECTORY_SEPARATOR === '\\') {
            return rtrim($appData, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'dw'.DIRECTORY_SEPARATOR.'config.json';
        }

        $home = self::envString('HOME');
        if ($home === null) {
            throw new \RuntimeException(
                'Unable to resolve dw config path: neither DW_CONFIG_HOME, XDG_CONFIG_HOME, '
                .'APPDATA, nor HOME is set.',
            );
        }

        return rtrim($home, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'.config'
            .DIRECTORY_SEPARATOR.'dw'.DIRECTORY_SEPARATOR.'config.json';
    }

    private static function envString(string $name): ?string
    {
        $value = $_ENV[$name] ?? getenv($name);
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}
