#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);

$env = static function (string $name): ?string {
    $value = getenv($name);

    return is_string($value) && trim($value) !== '' ? trim($value) : null;
};

$git = static function (string $arguments) use ($root): ?string {
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
};

$normalizeVersion = static function (?string $version): ?string {
    if ($version === null) {
        return null;
    }

    $version = trim($version);
    if ($version === '') {
        return null;
    }

    return ltrim($version, 'v');
};

$version = $normalizeVersion($env('DW_CLI_VERSION'));
if ($version === null && $env('GITHUB_REF_TYPE') === 'tag') {
    $version = $normalizeVersion($env('GITHUB_REF_NAME'));
}
$version ??= $normalizeVersion($git('describe --tags --exact-match'));
$version ??= '0.1.0-dev';

$commit = $env('DW_CLI_COMMIT') ?? $env('GITHUB_SHA') ?? $git('rev-parse HEAD') ?? 'unknown';
$buildDate = $env('DW_CLI_BUILD_DATE') ?? gmdate('Y-m-d\TH:i:s\Z');

$contents = <<<'PHP'
<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli;

final class GeneratedBuildInfo
{
    public const VERSION = %s;

    public const COMMIT = %s;

    public const BUILD_DATE = %s;
}
PHP;

$written = file_put_contents(
    $root.'/src/GeneratedBuildInfo.php',
    sprintf(
        $contents,
        var_export($version, true),
        var_export($commit, true),
        var_export($buildDate, true),
    ),
);

if ($written === false) {
    fwrite(STDERR, "Failed to write src/GeneratedBuildInfo.php\n");
    exit(1);
}

fwrite(STDOUT, sprintf(
    "Generated build info: version=%s commit=%s built=%s\n",
    $version,
    substr($commit, 0, 12),
    $buildDate,
));
