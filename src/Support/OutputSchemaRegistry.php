<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Support;

final class OutputSchemaRegistry
{
    private const MANIFEST = 'schemas/output/manifest.json';

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
        $entries = [];

        foreach (['commands', 'jsonl_commands'] as $group) {
            $commands = self::manifest()[$group] ?? null;

            if (! is_array($commands)) {
                throw new \RuntimeException(sprintf('Output schema manifest is missing a %s map.', $group));
            }

            foreach ($commands as $command => $entry) {
                if (! is_string($command) || ! is_array($entry)) {
                    continue;
                }

                $entries[] = ['command' => $command] + $entry;
            }
        }

        usort($entries, static function (array $left, array $right): int {
            $command = strcmp((string) $left['command'], (string) $right['command']);

            return $command !== 0
                ? $command
                : strcmp((string) ($left['output'] ?? ''), (string) ($right['output'] ?? ''));
        });

        return $entries;
    }

    public static function hasCommand(string $command): bool
    {
        $commands = self::manifest()['commands'] ?? [];

        return is_array($commands) && array_key_exists($command, $commands);
    }

    public static function hasJsonlCommand(string $command): bool
    {
        $commands = self::manifest()['jsonl_commands'] ?? [];

        return is_array($commands) && array_key_exists($command, $commands);
    }

    /**
     * @return array<string, mixed>
     */
    public static function entry(string $command, ?string $output = null): array
    {
        $group = self::manifestGroup($output);
        $commands = self::manifest()[$group] ?? null;

        if (! is_array($commands) || ! is_array($commands[$command] ?? null)) {
            throw new \InvalidArgumentException(sprintf(
                'No %s output schema is published for command [%s].',
                $group === 'jsonl_commands' ? 'JSONL' : 'JSON',
                $command,
            ));
        }

        /** @var array<string, mixed> $entry */
        $entry = $commands[$command];

        return $entry;
    }

    /**
     * @return array<string, mixed>
     */
    public static function schema(string $command, ?string $output = null): array
    {
        $entry = self::entry($command, $output);
        $file = $entry['schema'] ?? null;

        if (! is_string($file) || trim($file) === '') {
            throw new \RuntimeException(sprintf('Output schema entry for [%s] is missing a schema file.', $command));
        }

        return self::readJsonFile(self::absolutePath(self::safeRelativePath($file)));
    }

    public static function schemaPath(string $command, ?string $output = null): string
    {
        $entry = self::entry($command, $output);
        $file = $entry['schema'] ?? null;

        if (! is_string($file) || trim($file) === '') {
            throw new \RuntimeException(sprintf('Output schema entry for [%s] is missing a schema file.', $command));
        }

        return self::safeRelativePath($file);
    }

    private static function manifestGroup(?string $output): string
    {
        if ($output === null || in_array($output, ['json', '--json', '--output=json', 'stdout-json'], true)) {
            return 'commands';
        }

        if (in_array($output, ['jsonl', '--jsonl', '--output=jsonl'], true)) {
            return 'jsonl_commands';
        }

        throw new \InvalidArgumentException(sprintf('Unsupported output schema mode [%s].', $output));
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

        if (! str_starts_with($path, 'schemas/output/') || str_contains($path, '..')) {
            throw new \RuntimeException(sprintf('Unsafe output schema path [%s].', $path));
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
            throw new \RuntimeException(sprintf('Unable to read output schema file [%s].', $path));
        }

        try {
            $decoded = json_decode($contents, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException(sprintf(
                'Output schema file [%s] is invalid JSON: %s',
                $path,
                $exception->getMessage(),
            ), previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new \RuntimeException(sprintf('Output schema file [%s] must decode to a JSON object.', $path));
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
