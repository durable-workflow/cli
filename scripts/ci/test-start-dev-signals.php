<?php
declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';
require dirname(__DIR__, 2).'/tests/Support/StartDevSignalFixture.php';

use Tests\Support\StartDevSignalFixture;

$binary = $argv[1] ?? throw new RuntimeException('Pass the native CLI binary to verify.');
foreach (['sqlite', 'mysql'] as $database) {
    foreach ([2, 15] as $signal) {
        $result = StartDevSignalFixture::run([$binary], $database, $signal);
        if ($result['forwarded_signal'] !== $signal || $result['child_alive'] || $result['exit_code'] !== 128 + $signal) {
            throw new RuntimeException(json_encode($result, JSON_THROW_ON_ERROR));
        }
        if ($database === 'mysql' && str_contains($result['output'], 'Starting Laravel HTTP server')) {
            throw new RuntimeException('HTTP started after interrupted Compose launch.');
        }
        echo "PASS {$database} signal {$signal}: child stopped, exit ".(128 + $signal)."\n";
    }
}
