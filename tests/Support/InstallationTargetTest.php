<?php

declare(strict_types=1);

namespace Tests\Support;

use DurableWorkflow\Cli\Support\InstallationTarget;
use PHPUnit\Framework\TestCase;

class InstallationTargetTest extends TestCase
{
    private string $originalPath = '';

    private string $originalPathExt = '';

    protected function setUp(): void
    {
        $this->originalPath = getenv('PATH') ?: '';
        $this->originalPathExt = getenv('PATHEXT') ?: '';
    }

    public function test_detects_standalone_binary_on_linux_x86_64(): void
    {
        $path = $this->tempFile('dw');

        $target = InstallationTarget::detect(
            argv0: $path,
            osFamily: 'Linux',
            arch: 'x86_64',
        );

        self::assertSame(InstallationTarget::KIND_BINARY, $target->kind);
        self::assertTrue($target->upgradeable);
        self::assertSame('dw-linux-x86_64', $target->assetName);
        self::assertSame(realpath($path), $target->path);
    }

    public function test_detects_standalone_binary_on_linux_aarch64(): void
    {
        $path = $this->tempFile('dw');

        $target = InstallationTarget::detect(
            argv0: $path,
            osFamily: 'Linux',
            arch: 'aarch64',
        );

        self::assertTrue($target->upgradeable);
        self::assertSame('dw-linux-aarch64', $target->assetName);
    }

    public function test_detects_standalone_binary_on_darwin_aarch64(): void
    {
        $path = $this->tempFile('dw');

        $target = InstallationTarget::detect(
            argv0: $path,
            osFamily: 'Darwin',
            arch: 'arm64',
        );

        self::assertTrue($target->upgradeable);
        self::assertSame('dw-macos-aarch64', $target->assetName);
    }

    public function test_marks_darwin_x86_64_as_unsupported(): void
    {
        $path = $this->tempFile('dw');

        $target = InstallationTarget::detect(
            argv0: $path,
            osFamily: 'Darwin',
            arch: 'x86_64',
        );

        self::assertFalse($target->upgradeable);
        self::assertNull($target->assetName);
        self::assertStringContainsString('no published release asset', $target->reason);
    }

    public function test_detects_windows_x86_64(): void
    {
        $path = $this->tempFile('dw.exe');

        $target = InstallationTarget::detect(
            argv0: $path,
            osFamily: 'Windows',
            arch: 'AMD64',
        );

        self::assertTrue($target->upgradeable);
        self::assertSame('dw-windows-x86_64.exe', $target->assetName);
    }

    public function test_refuses_when_argv0_lives_inside_vendor(): void
    {
        $root = $this->tempDir('vendor-install');
        mkdir($root.'/vendor/durable-workflow/cli/bin', 0700, true);
        $path = $root.'/vendor/durable-workflow/cli/bin/dw';
        file_put_contents($path, '#!/usr/bin/env php');

        $target = InstallationTarget::detect(
            argv0: $path,
            osFamily: 'Linux',
            arch: 'x86_64',
        );

        self::assertSame(InstallationTarget::KIND_COMPOSER_VENDOR, $target->kind);
        self::assertFalse($target->upgradeable);
        self::assertStringContainsString('Composer', $target->reason);
    }

    public function test_refuses_phar_invocations(): void
    {
        $path = $this->tempFile('dw.phar');

        $target = InstallationTarget::detect(
            argv0: $path,
            pharRunning: $path,
            osFamily: 'Linux',
            arch: 'x86_64',
        );

        self::assertSame(InstallationTarget::KIND_PHAR, $target->kind);
        self::assertFalse($target->upgradeable);
        self::assertStringContainsString('PHAR', $target->reason);
    }

    public function test_refuses_homebrew_cellar_paths(): void
    {
        $root = $this->tempDir('homebrew-install');
        mkdir($root.'/Cellar/dw/0.1.5/bin', 0700, true);
        $path = $root.'/Cellar/dw/0.1.5/bin/dw';
        file_put_contents($path, '#!/usr/bin/env php');

        $target = InstallationTarget::detect(
            argv0: $path,
            osFamily: 'Linux',
            arch: 'x86_64',
        );

        self::assertSame(InstallationTarget::KIND_HOMEBREW, $target->kind);
        self::assertFalse($target->upgradeable);
        self::assertStringContainsString('Homebrew', $target->reason);
    }

    public function test_refuses_when_argv0_is_empty_and_phar_is_unknown(): void
    {
        $target = InstallationTarget::detect(
            argv0: '',
            pharRunning: '',
            osFamily: 'Linux',
            arch: 'x86_64',
        );

        self::assertSame(InstallationTarget::KIND_UNKNOWN, $target->kind);
        self::assertFalse($target->upgradeable);
    }

    /**
     * Running a `.phar` that lives under a vendor tree should be
     * classified as composer-managed, not a bare PHAR install — the
     * error message matters because it tells the user the right tool
     * to run.
     */
    public function test_composer_phar_classified_as_composer(): void
    {
        $root = $this->tempDir('composer-phar');
        mkdir($root.'/vendor/durable-workflow/cli', 0700, true);
        $path = $root.'/vendor/durable-workflow/cli/dw.phar';
        file_put_contents($path, 'PHAR');

        $target = InstallationTarget::detect(
            argv0: $path,
            pharRunning: $path,
            osFamily: 'Linux',
            arch: 'x86_64',
        );

        self::assertSame(InstallationTarget::KIND_COMPOSER_VENDOR, $target->kind);
        self::assertFalse($target->upgradeable);
    }

    public function test_detect_resolves_bare_command_name_from_path(): void
    {
        $dir = $this->tempDir('path-lookup');
        $binary = $dir.'/dw';
        file_put_contents($binary, "#!/bin/sh\nexit 0\n");
        chmod($binary, 0755);

        putenv('PATH='.$dir);

        $target = InstallationTarget::detect(
            argv0: 'dw',
            pharRunning: '',
            osFamily: 'Linux',
            arch: 'x86_64',
        );

        self::assertSame(InstallationTarget::KIND_BINARY, $target->kind);
        self::assertSame($binary, $target->path);
        self::assertTrue($target->upgradeable);
        self::assertSame('dw-linux-x86_64', $target->assetName);
    }

    private function tempFile(string $name): string
    {
        $dir = $this->tempDir('installation-'.bin2hex(random_bytes(4)));
        $path = $dir.'/'.$name;
        file_put_contents($path, '#!/usr/bin/env php');

        return $path;
    }

    private function tempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir().'/'.$prefix.'-'.bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        $this->registerCleanup($dir);

        return $dir;
    }

    /** @var list<string> */
    private array $cleanups = [];

    private function registerCleanup(string $path): void
    {
        $this->cleanups[] = $path;
    }

    protected function tearDown(): void
    {
        putenv('PATH='.$this->originalPath);
        if ($this->originalPathExt === '') {
            putenv('PATHEXT');
        } else {
            putenv('PATHEXT='.$this->originalPathExt);
        }

        foreach ($this->cleanups as $path) {
            $this->rmrf($path);
        }
        $this->cleanups = [];
    }

    private function rmrf(string $path): void
    {
        if (! file_exists($path) && ! is_link($path)) {
            return;
        }
        if (is_dir($path) && ! is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $this->rmrf($path.'/'.$entry);
            }
            @rmdir($path);

            return;
        }
        @unlink($path);
    }
}
