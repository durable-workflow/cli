<?php
declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;

final class StartDevSignalFixture
{
    public static function run(array $command, string $database, int $signal, bool $ignoreSignal = false, ?int $secondSignal = null): array
    {
        if (!function_exists('pcntl_signal') || !function_exists('posix_kill')) {
            throw new RuntimeException('Signal fixture requires external PHP with pcntl and posix.');
        }
        $directory = sys_get_temp_dir().'/dw-dev-signal-'.bin2hex(random_bytes(8));
        mkdir($directory);
        $childCode = <<<'PHP'
<?php
pcntl_async_signals(true);
foreach ([SIGINT, SIGTERM] as $signal) {
    pcntl_signal($signal, function (int $received): void {
        file_put_contents('stopped.signal', (string) $received);
        exit(0);
    });
}
file_put_contents('child.pid', (string) getmypid());
while (true) { usleep(100000); }
PHP;
        file_put_contents($directory.'/artisan', $childCode);
        file_put_contents($directory.'/docker', '#!'.PHP_BINARY."\n".$childCode);
        if ($ignoreSignal) {
            $childCode = str_replace('file_put_contents(\'child.pid\'', 'pcntl_signal(SIGINT, SIG_IGN); pcntl_signal(SIGTERM, SIG_IGN); file_put_contents(\'child.pid\'', $childCode);
            file_put_contents($directory.'/artisan', $childCode);
            file_put_contents($directory.'/docker', '#!'.PHP_BINARY."\n".$childCode);
        }
        chmod($directory.'/docker', 0755);
        $process = new Process([...$command, 'server:start-dev', '--db='.$database], $directory, [
            'PATH' => $directory.PATH_SEPARATOR.getenv('PATH'),
        ]);
        $process->setTimeout(12);
        $childPid = null;
        try {
            $process->start();
            $deadline = microtime(true) + 5;
            while (!is_file($directory.'/child.pid')) {
                if (!$process->isRunning() || microtime(true) >= $deadline) {
                    throw new RuntimeException('Child did not start: '.$process->getOutput().$process->getErrorOutput());
                }
                usleep(10000);
            }
            $childPid = (int) file_get_contents($directory.'/child.pid');
            $signaledAt = hrtime(true);
            $process->signal($signal);
            if ($secondSignal !== null) {
                usleep(2000000);
                $process->signal($secondSignal);
            }
            try {
                $process->wait();
            } catch (ProcessSignaledException) {
                // The unchanged launcher is killed before forwarding the signal.
            }
            $deadline = microtime(true) + 1;
            while (posix_kill($childPid, 0) && microtime(true) < $deadline) {
                usleep(10000);
            }
            return [
                'exit_code' => $process->getExitCode(),
                'forwarded_signal' => is_file($directory.'/stopped.signal') ? (int) file_get_contents($directory.'/stopped.signal') : null,
                'child_alive' => posix_kill($childPid, 0),
                'stop_seconds' => (hrtime(true) - $signaledAt) / 1_000_000_000,
                'output' => $process->getOutput().$process->getErrorOutput(),
            ];
        } finally {
            $process->stop(1);
            if ($childPid !== null && posix_kill($childPid, 0)) {
                posix_kill($childPid, SIGKILL);
            }
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
