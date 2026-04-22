<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\UpgradeCommand;
use DurableWorkflow\Cli\Commands\UpgradePermissionException;
use DurableWorkflow\Cli\Support\InstallationTarget;
use DurableWorkflow\Cli\Support\ReleaseCatalog;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class UpgradeCommandTest extends TestCase
{
    /** @var list<string> */
    private array $cleanups = [];

    protected function setUp(): void
    {
        putenv('DW_CLI_VERSION=0.1.5');
    }

    protected function tearDown(): void
    {
        putenv('DW_CLI_VERSION');
        foreach ($this->cleanups as $path) {
            $this->rmrf($path);
        }
        $this->cleanups = [];
    }

    public function test_refuses_composer_vendor_install(): void
    {
        $command = $this->command(
            catalog: $this->catalog(['latest-tag' => null, 'asset' => null, 'sums' => null]),
            detector: fn () => new InstallationTarget(
                kind: InstallationTarget::KIND_COMPOSER_VENDOR,
                path: '/opt/app/vendor/durable-workflow/cli/bin/dw',
                upgradeable: false,
                assetName: 'dw-linux-x86_64',
                reason: 'dw is installed as a Composer dependency. Use `composer update durable-workflow/cli` instead.',
            ),
            replacer: $this->rejectingReplacer(),
        );

        $tester = new CommandTester($command);
        $exit = $tester->execute(['--output' => 'json']);

        self::assertSame(Command::FAILURE, $exit);
        $decoded = $this->decode($tester->getDisplay());
        self::assertSame('refused', $decoded['status']);
        self::assertStringContainsString('Composer', $decoded['reason']);
    }

    public function test_refuses_phar_install(): void
    {
        $command = $this->command(
            catalog: $this->catalog(),
            detector: fn () => new InstallationTarget(
                kind: InstallationTarget::KIND_PHAR,
                path: '/usr/local/share/dw.phar',
                upgradeable: false,
                assetName: 'dw-linux-x86_64',
                reason: 'dw is running as a PHAR archive.',
            ),
            replacer: $this->rejectingReplacer(),
        );

        $tester = new CommandTester($command);
        $exit = $tester->execute(['--output' => 'json']);

        self::assertSame(Command::FAILURE, $exit);
        $decoded = $this->decode($tester->getDisplay());
        self::assertSame('refused', $decoded['status']);
    }

    public function test_noop_when_current_version_matches_latest(): void
    {
        $command = $this->command(
            catalog: $this->catalog(['latest-tag' => '0.1.5']),
            detector: fn () => new InstallationTarget(
                kind: InstallationTarget::KIND_BINARY,
                path: '/home/user/.local/bin/dw',
                upgradeable: true,
                assetName: 'dw-linux-x86_64',
            ),
            replacer: $this->rejectingReplacer(),
        );

        $tester = new CommandTester($command);
        $exit = $tester->execute(['--output' => 'json']);

        self::assertSame(Command::SUCCESS, $exit);
        $decoded = $this->decode($tester->getDisplay());
        self::assertSame('noop', $decoded['status']);
        self::assertSame('0.1.5', $decoded['current_version']);
        self::assertSame('0.1.5', $decoded['target_version']);
    }

    public function test_dry_run_reports_asset_url_without_downloading(): void
    {
        $command = $this->command(
            catalog: $this->catalog(['latest-tag' => '0.1.9']),
            detector: fn () => new InstallationTarget(
                kind: InstallationTarget::KIND_BINARY,
                path: '/home/user/.local/bin/dw',
                upgradeable: true,
                assetName: 'dw-linux-x86_64',
            ),
            replacer: $this->rejectingReplacer(),
        );

        $tester = new CommandTester($command);
        $exit = $tester->execute([
            '--dry-run' => true,
            '--output' => 'json',
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        $decoded = $this->decode($tester->getDisplay());
        self::assertSame('dry-run', $decoded['status']);
        self::assertSame('0.1.9', $decoded['target_version']);
        self::assertStringContainsString('0.1.9/dw-linux-x86_64', $decoded['asset_url']);
        self::assertStringContainsString('0.1.9/SHA256SUMS', $decoded['checksum_url']);
    }

    public function test_pinned_version_is_used(): void
    {
        $command = $this->command(
            catalog: $this->catalog(), // latestTag() never called
            detector: fn () => new InstallationTarget(
                kind: InstallationTarget::KIND_BINARY,
                path: '/home/user/.local/bin/dw',
                upgradeable: true,
                assetName: 'dw-linux-x86_64',
            ),
            replacer: $this->rejectingReplacer(),
        );

        $tester = new CommandTester($command);
        $exit = $tester->execute([
            '--version' => '0.1.3',
            '--dry-run' => true,
            '--output' => 'json',
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        $decoded = $this->decode($tester->getDisplay());
        self::assertSame('0.1.3', $decoded['target_version']);
    }

    public function test_full_upgrade_downloads_and_replaces_when_checksum_matches(): void
    {
        $binary = "#!/usr/bin/env dw\nnew-binary-bytes";
        $hash = hash('sha256', $binary);
        $sums = "{$hash}  dw-linux-x86_64\n";

        $replaced = [];
        $replacer = function (string $path, string $bytes, int $mode) use (&$replaced): void {
            $replaced[] = ['path' => $path, 'bytes' => $bytes, 'mode' => $mode];
        };

        $command = $this->command(
            catalog: $this->catalog([
                'latest-tag' => '0.1.9',
                'sums' => $sums,
                'asset' => $binary,
            ]),
            detector: fn () => new InstallationTarget(
                kind: InstallationTarget::KIND_BINARY,
                path: '/home/user/.local/bin/dw',
                upgradeable: true,
                assetName: 'dw-linux-x86_64',
            ),
            replacer: $replacer,
        );

        $tester = new CommandTester($command);
        $exit = $tester->execute(['--output' => 'json']);

        self::assertSame(Command::SUCCESS, $exit);
        $decoded = $this->decode($tester->getDisplay());
        self::assertSame('upgraded', $decoded['status']);
        self::assertSame('0.1.9', $decoded['target_version']);

        self::assertCount(1, $replaced);
        self::assertSame('/home/user/.local/bin/dw', $replaced[0]['path']);
        self::assertSame($binary, $replaced[0]['bytes']);
        self::assertSame(0755, $replaced[0]['mode']);
    }

    public function test_fails_on_checksum_mismatch(): void
    {
        $binary = 'mismatched-bytes';
        $wrongHash = str_repeat('f', 64);
        $sums = "{$wrongHash}  dw-linux-x86_64\n";

        $replacer = $this->rejectingReplacer();
        $command = $this->command(
            catalog: $this->catalog([
                'latest-tag' => '0.1.9',
                'sums' => $sums,
                'asset' => $binary,
            ]),
            detector: fn () => new InstallationTarget(
                kind: InstallationTarget::KIND_BINARY,
                path: '/home/user/.local/bin/dw',
                upgradeable: true,
                assetName: 'dw-linux-x86_64',
            ),
            replacer: $replacer,
        );

        $tester = new CommandTester($command);
        $exit = $tester->execute(['--output' => 'json']);

        self::assertSame(Command::FAILURE, $exit);
        $decoded = $this->decode($tester->getDisplay());
        self::assertSame('error', $decoded['status']);
        self::assertStringContainsString('checksum mismatch', $decoded['reason']);
    }

    public function test_reports_permission_denied_with_sudo_hint(): void
    {
        $binary = 'bytes';
        $hash = hash('sha256', $binary);
        $sums = "{$hash}  dw-linux-x86_64\n";

        $replacer = function (string $path, string $bytes, int $mode): void {
            throw new UpgradePermissionException('install directory /usr/local/bin is not writable');
        };

        $command = $this->command(
            catalog: $this->catalog([
                'latest-tag' => '0.1.9',
                'sums' => $sums,
                'asset' => $binary,
            ]),
            detector: fn () => new InstallationTarget(
                kind: InstallationTarget::KIND_BINARY,
                path: '/usr/local/bin/dw',
                upgradeable: true,
                assetName: 'dw-linux-x86_64',
            ),
            replacer: $replacer,
        );

        $tester = new CommandTester($command);
        $exit = $tester->execute(['--output' => 'json']);

        self::assertSame(Command::FAILURE, $exit);
        $decoded = $this->decode($tester->getDisplay());
        self::assertSame('permission-denied', $decoded['status']);
        self::assertStringContainsString('sudo dw upgrade', $decoded['hint']);
    }

    public function test_force_replaces_even_when_current_version_matches(): void
    {
        $binary = 'force-bytes';
        $hash = hash('sha256', $binary);
        $sums = "{$hash}  dw-linux-x86_64\n";

        $replaced = [];
        $replacer = function (string $path, string $bytes, int $mode) use (&$replaced): void {
            $replaced[] = $path;
        };

        $command = $this->command(
            catalog: $this->catalog([
                'latest-tag' => '0.1.5',
                'sums' => $sums,
                'asset' => $binary,
            ]),
            detector: fn () => new InstallationTarget(
                kind: InstallationTarget::KIND_BINARY,
                path: '/home/user/.local/bin/dw',
                upgradeable: true,
                assetName: 'dw-linux-x86_64',
            ),
            replacer: $replacer,
        );

        $tester = new CommandTester($command);
        $exit = $tester->execute([
            '--force' => true,
            '--output' => 'json',
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        $decoded = $this->decode($tester->getDisplay());
        self::assertSame('upgraded', $decoded['status']);
        self::assertCount(1, $replaced);
    }

    public function test_default_replacer_writes_atomically(): void
    {
        $dir = sys_get_temp_dir().'/dw-upgrade-replacer-'.bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        $this->cleanups[] = $dir;

        $target = $dir.'/dw';
        file_put_contents($target, 'old-binary');
        chmod($target, 0644);

        UpgradeCommand::defaultReplacer($target, 'new-binary', 0755);

        self::assertSame('new-binary', file_get_contents($target));
        self::assertSame(0755, fileperms($target) & 0777);
    }

    public function test_default_replacer_throws_on_unwritable_dir(): void
    {
        $dir = sys_get_temp_dir().'/dw-upgrade-ro-'.bin2hex(random_bytes(4));
        mkdir($dir, 0500, true);
        $this->cleanups[] = $dir;

        $target = $dir.'/dw';

        $this->expectException(UpgradePermissionException::class);
        try {
            UpgradeCommand::defaultReplacer($target, 'bytes', 0755);
        } finally {
            chmod($dir, 0700);
        }
    }

    public function test_human_output_prints_upgraded_message(): void
    {
        $binary = 'hi';
        $hash = hash('sha256', $binary);
        $sums = "{$hash}  dw-linux-x86_64\n";

        $command = $this->command(
            catalog: $this->catalog([
                'latest-tag' => '0.1.9',
                'sums' => $sums,
                'asset' => $binary,
            ]),
            detector: fn () => new InstallationTarget(
                kind: InstallationTarget::KIND_BINARY,
                path: '/home/user/.local/bin/dw',
                upgradeable: true,
                assetName: 'dw-linux-x86_64',
            ),
            replacer: fn () => null,
        );

        $tester = new CommandTester($command);
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        $display = $tester->getDisplay();
        self::assertStringContainsString('Upgraded dw to 0.1.9', $display);
        self::assertStringContainsString('/home/user/.local/bin/dw', $display);
    }

    public function test_rejects_invalid_output_mode(): void
    {
        $command = $this->command(
            catalog: $this->catalog(),
            detector: fn () => new InstallationTarget(
                kind: InstallationTarget::KIND_BINARY,
                path: '/home/user/.local/bin/dw',
                upgradeable: true,
                assetName: 'dw-linux-x86_64',
            ),
            replacer: $this->rejectingReplacer(),
        );

        $tester = new CommandTester($command);
        $exit = $tester->execute(['--output' => 'jsonl']);

        self::assertNotSame(Command::SUCCESS, $exit);
    }

    private function command(
        ReleaseCatalog $catalog,
        callable $detector,
        callable $replacer,
    ): UpgradeCommand {
        $command = new UpgradeCommand();
        $command->setReleaseCatalog($catalog);
        $command->setInstallationDetector($detector);
        $command->setBinaryReplacer($replacer);

        return $command;
    }

    /**
     * @param  array{latest-tag?: ?string, sums?: ?string, asset?: ?string}  $opts
     */
    private function catalog(array $opts = []): ReleaseCatalog
    {
        $responses = [];
        if (array_key_exists('latest-tag', $opts) && $opts['latest-tag'] !== null) {
            $tag = $opts['latest-tag'];
            $responses[] = new MockResponse('', [
                'http_code' => 302,
                'response_headers' => [
                    'Location: https://github.com/durable-workflow/cli/releases/tag/'.$tag,
                ],
            ]);
        }
        if (array_key_exists('sums', $opts) && $opts['sums'] !== null) {
            $responses[] = new MockResponse($opts['sums'], ['http_code' => 200]);
        }
        if (array_key_exists('asset', $opts) && $opts['asset'] !== null) {
            $responses[] = new MockResponse($opts['asset'], ['http_code' => 200]);
        }

        return new ReleaseCatalog(new MockHttpClient($responses), 'durable-workflow/cli');
    }

    private function rejectingReplacer(): callable
    {
        return function (string $path, string $bytes, int $mode): void {
            throw new \AssertionError('replacer should not have been called');
        };
    }

    private function decode(string $display): array
    {
        $decoded = json_decode(trim($display), true);
        self::assertIsArray($decoded, 'expected JSON output, got: '.$display);

        return $decoded;
    }

    private function rmrf(string $path): void
    {
        if (! file_exists($path) && ! is_link($path)) {
            return;
        }
        if (is_dir($path) && ! is_link($path)) {
            @chmod($path, 0700);
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
