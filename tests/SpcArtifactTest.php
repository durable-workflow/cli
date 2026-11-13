<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class SpcArtifactTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('The offline SPC acquisition fixture targets the Linux build jobs.');
        }
        $this->directory = sys_get_temp_dir().'/cli-spc-'.bin2hex(random_bytes(8));
        mkdir($this->directory.'/bin', 0700, true);
        file_put_contents($this->directory.'/bin/curl', <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
while [[ $# -gt 0 ]]; do
    case "$1" in
        --dump-header) headers="$2"; shift 2 ;;
        --output) output="$2"; shift 2 ;;
        *) url="$1"; shift ;;
    esac
done
printf '%s\n' "$url" >> "$SPC_TEST_ROOT/calls"
printf 'untrusted artifact' > "$output"
case "$SPC_TEST_CASE" in
    outside) location=https://127.0.0.1/private ;;
    insecure) location=http://release-assets.githubusercontent.com/asset ;;
    userinfo) location=https://release-assets.githubusercontent.com@127.0.0.1/private ;;
    loop) location=https://release-assets.githubusercontent.com/loop ;;
    oversized-headers) head -c 26000000 /dev/zero > "$headers" ;;
    duplicate)
        printf 'HTTP/1.1 302 Found\r\nLocation: https://release-assets.githubusercontent.com/a\r\nLocation: https://release-assets.githubusercontent.com/b\r\n\r\n' > "$headers"
        printf 302; exit 0 ;;
    allowed-corrupt)
        if [[ "$url" == https://release-assets.githubusercontent.com/* ]]; then
            printf 'HTTP/1.1 200 OK\r\n\r\n' > "$headers"
            printf 200; exit 0
        fi
        location=https://release-assets.githubusercontent.com/asset ;;
    *) exit 90 ;;
esac
printf 'HTTP/1.1 302 Found\r\nLocation: %s\r\n\r\n' "$location" > "$headers"
printf 302
BASH);
        chmod($this->directory.'/bin/curl', 0755);
    }

    protected function tearDown(): void
    {
        if (! isset($this->directory)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() && ! $file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    private function runAcquisition(string $case = 'outside', string $platform = 'linux-x86_64'): Process
    {
        $process = new Process(['bash', dirname(__DIR__).'/scripts/ci/ensure-spc.sh', $platform, $this->directory.'/spc'], null, [
            'PATH' => $this->directory.'/bin:'.getenv('PATH'),
            'SPC_TEST_ROOT' => $this->directory,
            'SPC_TEST_CASE' => $case,
        ]);
        $process->setTimeout(10);
        $process->run();

        return $process;
    }

    public function test_build_checks_the_spc_artifact_before_using_it(): void
    {
        $source = file_get_contents(dirname(__DIR__).'/scripts/build.sh');
        self::assertStringContainsString('bash "$ROOT/scripts/ci/ensure-spc.sh" "$platform" "$TOOLS_DIR/spc"', $source);
        self::assertLessThan(strpos($source, 'spc_download_with_retry --with-php='), strrpos($source, '    ensure_spc'));
        self::assertStringNotContainsString('spc-bin/nightly', $source);
    }

    public static function redirects(): array
    {
        return [
            'outside host' => ['outside', 1, 'outside the reviewed set'],
            'insecure scheme' => ['insecure', 1, 'outside the reviewed set'],
            'userinfo authority' => ['userinfo', 1, 'outside the reviewed set'],
            'duplicate location' => ['duplicate', 1, 'one Location header'],
            'redirect loop' => ['loop', 4, 'redirect limit'],
            'allowed host with corrupt body' => ['allowed-corrupt', 2, 'artifact size'],
            'oversized headers' => ['oversized-headers', 1, 'download failed'],
        ];
    }

    #[DataProvider('redirects')]
    public function test_redirect_and_body_failures_never_publish_an_executable(string $case, int $calls, string $reason): void
    {
        $process = $this->runAcquisition($case);
        self::assertFalse($process->isSuccessful());
        self::assertStringContainsString($reason, $process->getErrorOutput());
        self::assertCount($calls, file($this->directory.'/calls', FILE_IGNORE_NEW_LINES));
        self::assertFileDoesNotExist($this->directory.'/spc');
        self::assertSame([], glob($this->directory.'/.spc-download.*'));
    }

    public function test_unknown_platform_refuses_before_network_access(): void
    {
        self::assertFalse($this->runAcquisition(platform: 'unknown')->isSuccessful());
        self::assertFileDoesNotExist($this->directory.'/calls');
    }

    public function test_symlink_cache_refuses_without_following_or_downloading(): void
    {
        file_put_contents($this->directory.'/other', 'unchanged');
        symlink($this->directory.'/other', $this->directory.'/spc');
        self::assertFalse($this->runAcquisition()->isSuccessful());
        self::assertSame('unchanged', file_get_contents($this->directory.'/other'));
        self::assertFileDoesNotExist($this->directory.'/calls');
    }

    public function test_hardlink_cache_refuses_without_changing_the_other_link(): void
    {
        file_put_contents($this->directory.'/original', 'unchanged');
        link($this->directory.'/original', $this->directory.'/spc');
        self::assertFalse($this->runAcquisition()->isSuccessful());
        self::assertSame('unchanged', file_get_contents($this->directory.'/original'));
        self::assertFileDoesNotExist($this->directory.'/calls');
    }

    public function test_same_size_tampered_cache_refuses_before_execution_or_network(): void
    {
        file_put_contents($this->directory.'/spc', str_repeat('x', 4378067));
        chmod($this->directory.'/spc', 0755);
        $process = $this->runAcquisition();
        self::assertFalse($process->isSuccessful());
        self::assertStringContainsString('artifact digest', $process->getErrorOutput());
        self::assertFileDoesNotExist($this->directory.'/calls');
    }

    public function test_predecessor_executes_a_poisoned_cache_and_current_acquisition_refuses_it(): void
    {
        $marker = $this->directory.'/executed';
        file_put_contents($this->directory.'/spc', "#!/bin/sh\nprintf executed > ".escapeshellarg($marker)."\n");
        chmod($this->directory.'/spc', 0755);
        $old = new Process(['bash', __DIR__.'/fixtures/unverified-spc-cache.sh', $this->directory.'/spc']);
        $old->mustRun();
        self::assertFileExists($marker);
        unlink($marker);

        $helper = dirname(__DIR__).'/scripts/ci/ensure-spc.sh';
        $current = new Process(['bash', '-c', 'bash "$1" linux-x86_64 "$2" && "$2" --version',
            'spc-control', $helper, $this->directory.'/spc']);
        $current->run();
        self::assertFalse($current->isSuccessful());
        self::assertFileDoesNotExist($marker);
    }
}
