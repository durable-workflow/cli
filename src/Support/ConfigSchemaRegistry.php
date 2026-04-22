<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Support;

final class ConfigSchemaRegistry
{
    private const MANIFEST = 'schemas/config/manifest.json';

    /**
     * @return array<string, mixed>
     */
    public static function manifest(): array
    {
        return self::readJsonFile(self::absolutePath(self::MANIFEST));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function entries(): array
    {
        $schemas = self::manifest()['schemas'] ?? null;

        if (! is_array($schemas)) {
            throw new \RuntimeException('Config schema manifest is missing a schemas map.');
        }

        $entries = [];

        foreach ($schemas as $name => $entry) {
            if (! is_string($name) || ! is_array($entry)) {
                continue;
            }

            $entries[] = ['name' => $name] + $entry;
        }

        usort($entries, static fn (array $left, array $right): int => strcmp(
            (string) $left['name'],
            (string) $right['name'],
        ));

        return $entries;
    }

    public static function hasSchema(string $name): bool
    {
        $schemas = self::manifest()['schemas'] ?? [];

        return is_array($schemas) && array_key_exists($name, $schemas);
    }

    /**
     * @return array<string, mixed>
     */
    public static function entry(string $name): array
    {
        $schemas = self::manifest()['schemas'] ?? null;

        if (! is_array($schemas) || ! is_array($schemas[$name] ?? null)) {
            throw new \InvalidArgumentException(sprintf('No config schema is published for [%s].', $name));
        }

        /** @var array<string, mixed> $entry */
        $entry = $schemas[$name];

        return $entry;
    }

    /**
     * @return array<string, mixed>
     */
    public static function schema(string $name): array
    {
        $entry = self::entry($name);
        $file = $entry['schema'] ?? null;

        if (! is_string($file) || trim($file) === '') {
            throw new \RuntimeException(sprintf('Config schema entry for [%s] is missing a schema file.', $name));
        }

        return self::readJsonFile(self::absolutePath(self::safeRelativePath($file)));
    }

    private static function rootPath(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function absolutePath(string $relativePath): string
    {
        return self::rootPath().'/'.$relativePath;
    }

    private static function safeRelativePath(string $path): string
    {
        $path = ltrim($path, '/');

        if (! str_starts_with($path, 'schemas/config/') || str_contains($path, '..')) {
            throw new \RuntimeException(sprintf('Unsafe config schema path [%s].', $path));
        }

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private static function readJsonFile(string $path): array
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new \RuntimeException(sprintf('Unable to read config schema file [%s].', $path));
        }

        try {
            $decoded = json_decode($contents, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException(sprintf(
                'Config schema file [%s] is invalid JSON: %s',
                $path,
                $exception->getMessage(),
            ), previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new \RuntimeException(sprintf('Config schema file [%s] must decode to a JSON object.', $path));
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
