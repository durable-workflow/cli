<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli;

final class BuildInfo
{
    private const FALLBACK_VERSION = '0.1.0-dev';

    public static function consoleVersion(): string
    {
        return sprintf(
            '%s (commit %s, built %s)',
            self::version(),
            self::commit(),
            self::buildDate(),
        );
    }

    public static function version(): string
    {
        return self::generated('VERSION')
            ?? self::env('DW_CLI_VERSION')
            ?? self::tagVersion()
            ?? self::FALLBACK_VERSION;
    }

    public static function commit(): string
    {
        return self::shortCommit(
            self::generated('COMMIT')
            ?? self::env('DW_CLI_COMMIT')
            ?? self::env('GITHUB_SHA')
            ?? self::git('rev-parse HEAD')
            ?? 'unknown',
        );
    }

    public static function buildDate(): string
    {
        return self::generated('BUILD_DATE')
            ?? self::env('DW_CLI_BUILD_DATE')
            ?? 'source checkout';
    }

    private static function tagVersion(): ?string
    {
        if (self::env('GITHUB_REF_TYPE') === 'tag') {
            return self::normalizeVersion(self::env('GITHUB_REF_NAME'));
        }

        return self::normalizeVersion(self::git('describe --tags --exact-match'));
    }

    private static function normalizeVersion(?string $version): ?string
    {
        if ($version === null) {
            return null;
        }

        $version = trim($version);
        if ($version === '') {
            return null;
        }

        return ltrim($version, 'v');
    }

    private static function shortCommit(string $commit): string
    {
        $commit = trim($commit);
        if ($commit === '' || $commit === 'unknown') {
            return 'unknown';
        }

        return substr($commit, 0, 12);
    }

    private static function generated(string $constant): ?string
    {
        $class = __NAMESPACE__.'\\GeneratedBuildInfo';
        if (! class_exists($class)) {
            return null;
        }

        $name = $class.'::'.$constant;
        if (! defined($name)) {
            return null;
        }

        $value = constant($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function git(string $arguments): ?string
    {
        $root = dirname(__DIR__);
        if (! is_dir($root.'/.git')) {
            return null;
        }

        $command = sprintf(
            'git -C %s %s 2>/dev/null',
            escapeshellarg($root),
            $arguments,
        );

        $output = [];
        $exitCode = 1;
        exec($command, $output, $exitCode);

        if ($exitCode !== 0 || $output === []) {
            return null;
        }

        return trim($output[0]);
    }
}
