<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class UnixInstallerTest extends TestCase
{
    private string $fixtureRoot;

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('The Unix installer regression fixture targets the published Linux binary.');
        }

        $this->fixtureRoot = sys_get_temp_dir().'/dw-unix-installer-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->fixtureRoot, 0700, true));
    }

    protected function tearDown(): void
    {
        if (! isset($this->fixtureRoot) || ! is_dir($this->fixtureRoot)) {
            return;
        }

        $directories = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->fixtureRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($directories as $entry) {
            if ($entry->isDir()) {
                chmod($entry->getPathname(), 0700);
            }
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->fixtureRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            if ($entry->isDir()) {
                chmod($entry->getPathname(), 0700);
                rmdir($entry->getPathname());
            } else {
                chmod($entry->getPathname(), 0600);
                unlink($entry->getPathname());
            }
        }

        chmod($this->fixtureRoot, 0700);
        rmdir($this->fixtureRoot);
    }

    public function test_shadowed_non_writable_system_binary_is_reported_and_remediated(): void
    {
        $version = '2.0.0-rc.35';
        $systemBin = $this->fixtureRoot.'/system-bin';
        $installBin = $this->fixtureRoot."/user bin's\\path";
        $releaseRoot = $this->fixtureRoot.'/releases';
        $releaseDir = $releaseRoot.'/download/'.$version;

        self::assertTrue(mkdir($systemBin, 0700));
        self::assertTrue(mkdir($installBin, 0700));
        self::assertTrue(mkdir($releaseDir, 0700, true));

        $oldBinary = $this->binary('2.0.0-rc.34');
        self::assertNotFalse(file_put_contents($systemBin.'/dw', $oldBinary));
        self::assertTrue(chmod($systemBin.'/dw', 0555));
        self::assertTrue(chmod($systemBin, 0555));

        $asset = match (php_uname('m')) {
            'x86_64', 'amd64' => 'dw-linux-x86_64',
            'arm64', 'aarch64' => 'dw-linux-aarch64',
            default => throw new \RuntimeException('Unsupported test architecture: '.php_uname('m')),
        };
        $newBinary = $this->binary($version);
        self::assertNotFalse(file_put_contents($releaseDir.'/'.$asset, $newBinary));
        self::assertNotFalse(file_put_contents(
            $releaseDir.'/SHA256SUMS',
            hash('sha256', $newBinary).'  '.$asset."\n",
        ));

        $path = implode(':', [$systemBin, $installBin, '/usr/local/bin', '/usr/bin', '/bin']);
        $process = new Process(
            ['/bin/sh', dirname(__DIR__).'/scripts/install.sh'],
            dirname(__DIR__),
            [
                'DURABLE_WORKFLOW_INSTALL_DIR' => $installBin,
                'DURABLE_WORKFLOW_INSTALL_OUTPUT' => 'json',
                'DURABLE_WORKFLOW_RELEASE_BASE_URL' => 'file://'.$releaseRoot,
                'HOME' => $this->fixtureRoot.'/home',
                'PATH' => $path,
                'SHELL' => '/bin/bash',
                'VERSION' => $version,
            ],
        );
        $process->run();

        self::assertSame(1, $process->getExitCode(), $process->getErrorOutput());
        $result = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('durable-workflow.cli.install.v1', $result['schema']);
        self::assertSame('path-shadowed', $result['status']);
        self::assertSame($installBin.'/dw', $result['installed_path']);
        self::assertSame($systemBin.'/dw', $result['active_path']);
        self::assertSame('dw '.$version, $result['installed_version']);
        self::assertSame('dw 2.0.0-rc.34', $result['active_version']);
        self::assertSame(0555, fileperms($systemBin) & 0777);
        self::assertSame($oldBinary, file_get_contents($systemBin.'/dw'));
        self::assertSame($newBinary, file_get_contents($installBin.'/dw'));

        self::assertSame($this->fixtureRoot.'/home/.bashrc', $result['remediation']['shell_profile']);
        self::assertSame(
            'export PATH='.$this->shellQuote($installBin).':"$PATH"; hash -d \'dw\' 2>/dev/null || :',
            $result['remediation']['current_shell'],
        );
        self::assertSame(
            'export PATH='.$this->shellQuote($installBin).':"$PATH"',
            $result['remediation']['persistent_line'],
        );
        self::assertStringNotContainsString($path, $process->getOutput());
        self::assertStringNotContainsString($path, $process->getErrorOutput());

        $remediated = new Process(
            ['/bin/bash', '-c', $result['remediation']['current_shell'].'; command -v dw; dw --version'],
            dirname(__DIR__),
            ['PATH' => $path],
        );
        $remediated->run();

        self::assertSame(0, $remediated->getExitCode(), $remediated->getErrorOutput());
        self::assertSame(
            [$installBin.'/dw', 'dw '.$version],
            preg_split('/\R/', trim($remediated->getOutput())),
        );

        $qualified = new Process(
            ['/bin/sh', dirname(__DIR__).'/scripts/install.sh'],
            dirname(__DIR__),
            [
                'DURABLE_WORKFLOW_INSTALL_DIR' => $installBin,
                'DURABLE_WORKFLOW_INSTALL_OUTPUT' => 'json',
                'DURABLE_WORKFLOW_RELEASE_BASE_URL' => 'file://'.$releaseRoot,
                'HOME' => $this->fixtureRoot.'/home',
                'PATH' => $installBin.':'.$path,
                'SHELL' => '/bin/bash',
                'VERSION' => $version,
            ],
        );
        $qualified->run();

        self::assertSame(0, $qualified->getExitCode(), $qualified->getErrorOutput());
        $qualifiedResult = json_decode(trim($qualified->getOutput()), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('ready', $qualifiedResult['status']);
        self::assertSame($installBin.'/dw', $qualifiedResult['installed_path']);
        self::assertSame($installBin.'/dw', $qualifiedResult['active_path']);
        self::assertSame('dw '.$version, $qualifiedResult['installed_version']);
        self::assertSame('dw '.$version, $qualifiedResult['active_version']);
        self::assertNull($qualifiedResult['remediation']);
    }

    #[DataProvider('hashingShellProvider')]
    public function test_stale_hash_is_reported_and_remediated_in_the_invoking_shell(
        string $shellPath,
        string $shellEnvironmentPath,
        string $expectedRemediation,
    ): void
    {
        $version = '2.0.0-rc.35';
        $systemBin = $this->fixtureRoot.'/system-bin';
        $installBin = $this->fixtureRoot.'/user-bin';
        $releaseRoot = $this->fixtureRoot.'/releases';
        $releaseDir = $releaseRoot.'/download/'.$version;

        self::assertTrue(mkdir($systemBin, 0700));
        self::assertTrue(mkdir($installBin, 0700));
        self::assertTrue(mkdir($releaseDir, 0700, true));

        $oldBinary = $this->binary('2.0.0-rc.34');
        self::assertNotFalse(file_put_contents($systemBin.'/dw', $oldBinary));
        self::assertTrue(chmod($systemBin.'/dw', 0555));
        self::assertTrue(chmod($systemBin, 0555));

        $asset = match (php_uname('m')) {
            'x86_64', 'amd64' => 'dw-linux-x86_64',
            'arm64', 'aarch64' => 'dw-linux-aarch64',
            default => throw new \RuntimeException('Unsupported test architecture: '.php_uname('m')),
        };
        $newBinary = $this->binary($version);
        self::assertNotFalse(file_put_contents($releaseDir.'/'.$asset, $newBinary));
        self::assertNotFalse(file_put_contents(
            $releaseDir.'/SHA256SUMS',
            hash('sha256', $newBinary).'  '.$asset."\n",
        ));

        $resultPath = $this->fixtureRoot.'/install-result.json';
        $installerExitPath = $this->fixtureRoot.'/installer-exit';
        $beforePath = $this->fixtureRoot.'/before-path';
        $beforeVersion = $this->fixtureRoot.'/before-version';
        $cachedPath = $this->fixtureRoot.'/cached-path';
        $cachedVersion = $this->fixtureRoot.'/cached-version';
        $remediationPath = $this->fixtureRoot.'/remediation';
        $remediatedPath = $this->fixtureRoot.'/remediated-path';
        $remediatedVersion = $this->fixtureRoot.'/remediated-version';
        $path = implode(':', [$installBin, $systemBin, '/usr/local/bin', '/usr/bin', '/bin']);

        $process = new Process(
            [$shellPath, '-c', <<<'SH'
dw --version > "$BEFORE_VERSION"
command -v dw > "$BEFORE_PATH"

set +e
DURABLE_WORKFLOW_INSTALL_OUTPUT=json /bin/sh "$INSTALLER" > "$RESULT_PATH"
printf '%s\n' "$?" > "$INSTALLER_EXIT_PATH"
set -e

command -v dw > "$CACHED_PATH"
dw --version > "$CACHED_VERSION"

"$PHP_BINARY_PATH" -r '
$result = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
echo $result["remediation"]["current_shell"];
' "$RESULT_PATH" > "$REMEDIATION_PATH"
current_shell_remediation=$(cat "$REMEDIATION_PATH")
eval "$current_shell_remediation"

command -v dw > "$REMEDIATED_PATH"
dw --version > "$REMEDIATED_VERSION"
SH],
            dirname(__DIR__),
            [
                'BEFORE_PATH' => $beforePath,
                'BEFORE_VERSION' => $beforeVersion,
                'CACHED_PATH' => $cachedPath,
                'CACHED_VERSION' => $cachedVersion,
                'DURABLE_WORKFLOW_INSTALL_DIR' => $installBin,
                'DURABLE_WORKFLOW_RELEASE_BASE_URL' => 'file://'.$releaseRoot,
                'HOME' => $this->fixtureRoot.'/home',
                'INSTALLER' => dirname(__DIR__).'/scripts/install.sh',
                'INSTALLER_EXIT_PATH' => $installerExitPath,
                'PATH' => $path,
                'PHP_BINARY_PATH' => PHP_BINARY,
                'REMEDIATED_PATH' => $remediatedPath,
                'REMEDIATED_VERSION' => $remediatedVersion,
                'REMEDIATION_PATH' => $remediationPath,
                'RESULT_PATH' => $resultPath,
                'SHELL' => $shellEnvironmentPath,
                'VERSION' => $version,
            ],
        );
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame($systemBin.'/dw', trim((string) file_get_contents($beforePath)));
        self::assertSame('dw 2.0.0-rc.34', trim((string) file_get_contents($beforeVersion)));
        self::assertSame('1', trim((string) file_get_contents($installerExitPath)));

        $result = json_decode((string) file_get_contents($resultPath), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('shell-cache-refresh-required', $result['status']);
        self::assertSame($installBin.'/dw', $result['installed_path']);
        self::assertSame($systemBin.'/dw', $result['active_path']);
        self::assertSame('dw '.$version, $result['installed_version']);
        self::assertSame('dw 2.0.0-rc.34', $result['active_version']);
        self::assertSame($expectedRemediation, $result['remediation']['current_shell']);
        self::assertNull($result['remediation']['shell_profile']);
        self::assertNull($result['remediation']['persistent_line']);

        self::assertSame($systemBin.'/dw', trim((string) file_get_contents($cachedPath)));
        self::assertSame('dw 2.0.0-rc.34', trim((string) file_get_contents($cachedVersion)));
        self::assertSame($installBin.'/dw', trim((string) file_get_contents($remediatedPath)));
        self::assertSame('dw '.$version, trim((string) file_get_contents($remediatedVersion)));
    }

    public static function hashingShellProvider(): iterable
    {
        yield 'Bash' => ['/bin/bash', '/bin/bash', "hash -d 'dw' 2>/dev/null || :"];
        yield 'dash' => ['/bin/dash', '/bin/dash', "hash 'dw'"];
        yield 'dash overrides login shell metadata' => ['/bin/dash', '/bin/bash', "hash 'dw'"];
    }

    private function binary(string $version): string
    {
        return "#!/bin/sh\n".
            "if [ \"\${1:-}\" = \"--version\" ]; then\n".
            "    printf 'dw %s\\n' ".escapeshellarg($version)."\n".
            "    exit 0\n".
            "fi\n".
            "exit 2\n";
    }

    private function shellQuote(string $value): string
    {
        return "'".str_replace("'", "'\\''", $value)."'";
    }
}
