<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands;

use DurableWorkflow\Cli\BuildInfo;
use DurableWorkflow\Cli\Support\ExitCode;
use DurableWorkflow\Cli\Support\InstallationTarget;
use DurableWorkflow\Cli\Support\InvalidOptionException;
use DurableWorkflow\Cli\Support\OutputMode;
use DurableWorkflow\Cli\Support\ReleaseCatalog;
use DurableWorkflow\Cli\Support\ReleaseCatalogException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `dw upgrade` — replace the running standalone `dw` binary with a
 * newer (or pinned) release.
 *
 * The command deliberately refuses to rewrite installations that are
 * managed by another tool (Composer vendor, Homebrew cellar, or a bare
 * PHAR invocation) and points the operator at the right command for
 * that tool instead.
 */
class UpgradeCommand extends Command
{
    private ?ReleaseCatalog $catalog = null;

    /**
     * @var (callable(): InstallationTarget)|null
     */
    private $detector = null;

    /**
     * @var (callable(string, string, int): bool)|null
     */
    private $replacer = null;

    protected function configure(): void
    {
        $this->setName('upgrade')
            ->setDescription('Upgrade the standalone dw binary to the latest (or a pinned) release')
            ->setHelp(<<<'HELP'
Replace the currently running `dw` binary with a newer release from
`durable-workflow/cli` on GitHub. The command verifies the downloaded
asset against `SHA256SUMS` before replacing the binary, and only
rewrites standalone release installs — Composer, Homebrew, and PHAR
installs are refused with a pointer at the right managing tool.

<comment>Examples:</comment>

  <info>dw upgrade</info>
    <info>dw upgrade --tag=0.1.5</info>
  <info>dw upgrade --dry-run</info>
  <info>dw upgrade --output=json</info>
HELP)
                        ->addOption('tag', null, InputOption::VALUE_REQUIRED, 'Release tag to install (defaults to the latest release)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Resolve the target release without downloading or replacing')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Re-download and replace even when the current and target versions match')
            ->addOption(
                'output',
                null,
                InputOption::VALUE_REQUIRED,
                'Output format: table (human-readable) or json',
                OutputMode::TABLE,
                [OutputMode::TABLE, OutputMode::JSON],
            );
    }

    public function setReleaseCatalog(ReleaseCatalog $catalog): void
    {
        $this->catalog = $catalog;
    }

    public function setInstallationDetector(callable $detector): void
    {
        $this->detector = $detector;
    }

    public function setBinaryReplacer(callable $replacer): void
    {
        $this->replacer = $replacer;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $outputMode = $this->resolveOutputMode($input);
        } catch (InvalidOptionException $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return $e->exitCode();
        }
        $asJson = $outputMode === OutputMode::JSON;

        // Prime exit-code constants before any self-replacement so the current
        // process never needs to autoload ExitCode from a swapped archive.
        $successExit = ExitCode::SUCCESS;
        $failureExit = ExitCode::FAILURE;

        $target = $this->detector !== null ? ($this->detector)() : InstallationTarget::detect(
            argv0: (string) ($_SERVER['argv'][0] ?? ''),
            pharRunning: \Phar::running(false),
        );

        $currentVersion = BuildInfo::version();
        $requestedTag = $input->getOption('tag');
        if (is_string($requestedTag)) {
            $requestedTag = ltrim(trim($requestedTag), 'v');
            if ($requestedTag === '') {
                $requestedTag = null;
            }
        } else {
            $requestedTag = null;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $force = (bool) $input->getOption('force');

        if (! $target->upgradeable && ! $dryRun) {
            return $this->emit($output, $asJson, [
                'status' => 'refused',
                'reason' => $target->reason,
                'installation' => $target->toArray(),
                'current_version' => $currentVersion,
                'target_version' => null,
            ], $failureExit);
        }

        $catalog = $this->catalog ?? ReleaseCatalog::create();

        try {
            $targetVersion = $requestedTag ?? $catalog->latestTag();
        } catch (ReleaseCatalogException $e) {
            return $this->emit($output, $asJson, [
                'status' => 'error',
                'reason' => $e->getMessage(),
                'installation' => $target->toArray(),
                'current_version' => $currentVersion,
                'target_version' => null,
            ], $failureExit);
        }

        if ($target->assetName === null) {
            return $this->emit($output, $asJson, [
                'status' => 'refused',
                'reason' => $target->reason !== '' ? $target->reason : 'no release asset maps to this platform',
                'installation' => $target->toArray(),
                'current_version' => $currentVersion,
                'target_version' => $targetVersion,
            ], $failureExit);
        }

        $sameVersion = $this->versionsMatch($currentVersion, $targetVersion);

        if ($sameVersion && ! $force) {
            return $this->emit($output, $asJson, [
                'status' => 'noop',
                'reason' => sprintf('dw is already at %s', $currentVersion),
                'installation' => $target->toArray(),
                'current_version' => $currentVersion,
                'target_version' => $targetVersion,
            ], $successExit);
        }

        $binaryUrl = $catalog->downloadUrl($targetVersion, $target->assetName);
        $sumsUrl = $catalog->downloadUrl($targetVersion, 'SHA256SUMS');

        if ($dryRun) {
            return $this->emit($output, $asJson, [
                'status' => 'dry-run',
                'installation' => $target->toArray(),
                'current_version' => $currentVersion,
                'target_version' => $targetVersion,
                'asset_url' => $binaryUrl,
                'checksum_url' => $sumsUrl,
            ], $successExit);
        }

        if (! $target->upgradeable) {
            return $this->emit($output, $asJson, [
                'status' => 'refused',
                'reason' => $target->reason,
                'installation' => $target->toArray(),
                'current_version' => $currentVersion,
                'target_version' => $targetVersion,
            ], $failureExit);
        }

        try {
            $sumsBody = $catalog->fetch($sumsUrl);
            $expected = ReleaseCatalog::lookupChecksum($sumsBody, $target->assetName);
            $binary = $catalog->fetch($binaryUrl);
        } catch (ReleaseCatalogException $e) {
            return $this->emit($output, $asJson, [
                'status' => 'error',
                'reason' => $e->getMessage(),
                'installation' => $target->toArray(),
                'current_version' => $currentVersion,
                'target_version' => $targetVersion,
            ], $failureExit);
        }

        $actual = hash('sha256', $binary);
        if (! hash_equals($expected, $actual)) {
            return $this->emit($output, $asJson, [
                'status' => 'error',
                'reason' => sprintf('checksum mismatch for %s (expected %s, got %s)', $target->assetName, $expected, $actual),
                'installation' => $target->toArray(),
                'current_version' => $currentVersion,
                'target_version' => $targetVersion,
            ], $failureExit);
        }

        $replacer = $this->replacer ?? self::defaultReplacer(...);
        try {
            $replacer($target->path, $binary, 0755);
        } catch (UpgradePermissionException $e) {
            return $this->emit($output, $asJson, [
                'status' => 'permission-denied',
                'reason' => $e->getMessage(),
                'hint' => $this->permissionHint($target->path),
                'installation' => $target->toArray(),
                'current_version' => $currentVersion,
                'target_version' => $targetVersion,
            ], $failureExit);
        } catch (\RuntimeException $e) {
            return $this->emit($output, $asJson, [
                'status' => 'error',
                'reason' => $e->getMessage(),
                'installation' => $target->toArray(),
                'current_version' => $currentVersion,
                'target_version' => $targetVersion,
            ], $failureExit);
        }

        return $this->emit($output, $asJson, [
            'status' => 'upgraded',
            'installation' => $target->toArray(),
            'current_version' => $currentVersion,
            'target_version' => $targetVersion,
        ], $successExit);
    }

    private function emit(OutputInterface $output, bool $asJson, array $payload, int $exit): int
    {
        if ($asJson) {
            $output->writeln(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return $exit;
        }

        $this->renderHuman($output, $payload);

        return $exit;
    }

    private function renderHuman(OutputInterface $output, array $payload): void
    {
        switch ($payload['status']) {
            case 'noop':
                $output->writeln(sprintf('<info>dw is already at %s</info>', (string) ($payload['current_version'] ?? 'unknown')));
                break;
            case 'dry-run':
                $output->writeln(sprintf('<info>Would upgrade %s -> %s</info>', (string) ($payload['current_version'] ?? ''), (string) ($payload['target_version'] ?? '')));
                $output->writeln(sprintf('  asset:    %s', (string) ($payload['asset_url'] ?? '')));
                $output->writeln(sprintf('  checksum: %s', (string) ($payload['checksum_url'] ?? '')));
                break;
            case 'upgraded':
                $output->writeln(sprintf('<info>Upgraded dw to %s</info>', (string) ($payload['target_version'] ?? '')));
                $output->writeln(sprintf('  path: %s', (string) ($payload['installation']['path'] ?? '')));
                break;
            case 'refused':
                $output->writeln(sprintf('<error>Upgrade refused:</error> %s', (string) ($payload['reason'] ?? '')));
                break;
            case 'permission-denied':
                $output->writeln(sprintf('<error>Upgrade failed:</error> %s', (string) ($payload['reason'] ?? '')));
                if (isset($payload['hint']) && is_string($payload['hint']) && $payload['hint'] !== '') {
                    $output->writeln('  ' . $payload['hint']);
                }
                break;
            default:
                $output->writeln(sprintf('<error>Upgrade failed:</error> %s', (string) ($payload['reason'] ?? 'unknown error')));
        }
    }

    private function resolveOutputMode(InputInterface $input): string
    {
        $mode = $input->getOption('output');
        if (! is_string($mode) || $mode === '') {
            return OutputMode::TABLE;
        }
        if (! in_array($mode, [OutputMode::TABLE, OutputMode::JSON], true)) {
            throw new InvalidOptionException(sprintf('--output must be one of: %s, %s', OutputMode::TABLE, OutputMode::JSON));
        }

        return $mode;
    }

    private function versionsMatch(string $current, string $target): bool
    {
        $normalize = static function (string $value): string {
            $value = trim($value);
            // BuildInfo::version() may return "0.1.5-dev" or "0.1.5" or fallback
            // "0.1.0-dev"; only compare the core semver head for the noop check.
            if (preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?/', ltrim($value, 'v'), $m)) {
                return $m[0];
            }

            return ltrim($value, 'v');
        };

        return $normalize($current) === $normalize($target);
    }

    private function permissionHint(string $path): string
    {
        $dir = dirname($path);
        if (str_starts_with($path, '/usr/') || str_starts_with($path, '/opt/')) {
            return sprintf('Re-run with elevated privileges (e.g. `sudo dw upgrade`) or reinstall to a user-writable path such as ~/.local/bin and re-run `dw upgrade`.');
        }

        return sprintf('Re-run with write access to %s, or reinstall to a user-writable path such as ~/.local/bin and re-run `dw upgrade`.', $dir);
    }

    public static function defaultReplacer(string $destination, string $bytes, int $mode = 0755): void
    {
        $dir = dirname($destination);
        if (! is_dir($dir)) {
            throw new \RuntimeException(sprintf('install directory %s does not exist', $dir));
        }
        if (! is_writable($dir)) {
            throw new UpgradePermissionException(sprintf('install directory %s is not writable', $dir));
        }

        $temp = $dir.'/.dw-upgrade-'.bin2hex(random_bytes(6));
        $handle = @fopen($temp, 'wb');
        if ($handle === false) {
            throw new UpgradePermissionException(sprintf('could not create temp file in %s', $dir));
        }

        $written = fwrite($handle, $bytes);
        fclose($handle);
        if ($written === false || $written !== strlen($bytes)) {
            @unlink($temp);
            throw new \RuntimeException(sprintf('short write while staging %s', $temp));
        }

        if (! @chmod($temp, $mode)) {
            @unlink($temp);
            throw new \RuntimeException(sprintf('could not chmod %o on %s', $mode, $temp));
        }

        if (! @rename($temp, $destination)) {
            @unlink($temp);
            throw new UpgradePermissionException(sprintf('could not replace %s (destination not writable)', $destination));
        }
    }
}
