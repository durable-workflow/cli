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
$forced = StartDevSignalFixture::run([$binary], 'sqlite', 15, true);
if ($forced['child_alive'] || $forced['exit_code'] !== 143 || $forced['stop_seconds'] >= 7) {
    throw new RuntimeException('Ignored signal exceeded cleanup budget: '.json_encode($forced, JSON_THROW_ON_ERROR));
}
echo "PASS ignored SIGTERM: child stopped within the five-second budget\n";
$repeated = StartDevSignalFixture::run([$binary], 'sqlite', 2, true, 15);
if ($repeated['child_alive'] || $repeated['exit_code'] !== 130 || $repeated['stop_seconds'] >= 6.5) {
    throw new RuntimeException('Second interrupt extended cleanup: '.json_encode($repeated, JSON_THROW_ON_ERROR));
}
echo "PASS repeated interrupt: original five-second deadline preserved\n";
