<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Support;

/**
 * Describes the currently-running `dw` binary: where it lives, which
 * platform asset it maps to, and whether the binary is safe for the
 * built-in self-updater to replace.
 *
 * The self-updater only rewrites standalone release binaries. Composer
 * vendor PHARs, Homebrew cellar binaries, and other package-manager-
 * managed locations are marked not upgradeable so the command can tell
 * the user to use the managing tool instead.
 */
final class InstallationTarget
{
    public const KIND_BINARY = 'binary';
    public const KIND_COMPOSER_VENDOR = 'composer-vendor';
    public const KIND_HOMEBREW = 'homebrew';
    public const KIND_PHAR = 'phar';
    public const KIND_UNKNOWN = 'unknown';

    /**
     * @param  self::KIND_*  $kind
     * @param  non-empty-string|null  $assetName
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $path,
        public readonly bool $upgradeable,
        public readonly ?string $assetName,
        public readonly string $reason = '',
    ) {
    }

    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'path' => $this->path,
            'upgradeable' => $this->upgradeable,
            'asset' => $this->assetName,
            'reason' => $this->reason,
        ];
    }

    /**
     * Detect the current installation using the supplied argv[0] and
     * (optionally) Phar::running() output. Inputs are injected so tests
     * can exercise every branch without running from a real binary.
     *
     * @param  self::KIND_*|null  $os  Linux/Darwin/WINNT (from PHP_OS_FAMILY)
     */
    public static function detect(
        string $argv0,
        string $pharRunning = '',
        ?string $osFamily = null,
        ?string $arch = null,
    ): self {
        $osFamily = $osFamily ?? PHP_OS_FAMILY;
        $arch = $arch ?? php_uname('m');

        $asset = self::mapAsset($osFamily, $arch);

        // Phar::running() returns the inner phar path (without `phar://`).
        // For standalone spc-compiled binaries this points at the wrapped
        // phar entry, typically with the binary's own filename. For a
        // traditional `php dw.phar` invocation it points at the real .phar
        // file on disk.
        $pharPath = $pharRunning !== '' ? $pharRunning : '';

        $resolved = $argv0 !== '' ? (realpath($argv0) ?: $argv0) : '';
        $pharResolved = $pharPath !== '' ? (realpath($pharPath) ?: $pharPath) : '';

        // Traditional PHAR invocation: `php path/to/dw.phar`.
        // Reject when the phar lives under a composer vendor/ tree —
        // composer owns that file.
        if ($pharResolved !== '' && self::looksLikePhar($pharResolved)) {
            if (self::isInsideVendor($pharResolved)) {
                return new self(
                    kind: self::KIND_COMPOSER_VENDOR,
                    path: $pharResolved,
                    upgradeable: false,
                    assetName: $asset,
                    reason: 'dw is installed as a Composer dependency. Use `composer update durable-workflow/cli` instead.',
                );
            }

            return new self(
                kind: self::KIND_PHAR,
                path: $pharResolved,
                upgradeable: false,
                assetName: $asset,
                reason: 'dw is running as a PHAR archive. Download the matching PHAR manually from the release page; `dw upgrade` only replaces standalone binaries.',
            );
        }

        if ($resolved === '') {
            return new self(
                kind: self::KIND_UNKNOWN,
                path: '',
                upgradeable: false,
                assetName: $asset,
                reason: 'could not resolve the dw executable path',
            );
        }

        if (self::isInsideVendor($resolved)) {
            return new self(
                kind: self::KIND_COMPOSER_VENDOR,
                path: $resolved,
                upgradeable: false,
                assetName: $asset,
                reason: 'dw is installed as a Composer dependency. Use `composer update durable-workflow/cli` instead.',
            );
        }

        if (self::isInsideHomebrew($resolved)) {
            return new self(
                kind: self::KIND_HOMEBREW,
                path: $resolved,
                upgradeable: false,
                assetName: $asset,
                reason: 'dw is installed via Homebrew. Use `brew upgrade durable-workflow/tap/dw` instead.',
            );
        }

        if ($asset === null) {
            return new self(
                kind: self::KIND_BINARY,
                path: $resolved,
                upgradeable: false,
                assetName: null,
                reason: sprintf('no published release asset matches this platform (%s/%s)', $osFamily, $arch),
            );
        }

        return new self(
            kind: self::KIND_BINARY,
            path: $resolved,
            upgradeable: true,
            assetName: $asset,
        );
    }

    /**
     * Map the current platform to a published release asset name.
     * Returns null when the platform has no matching binary.
     */
    public static function mapAsset(string $osFamily, string $arch): ?string
    {
        $archKey = match (strtolower($arch)) {
            'x86_64', 'amd64' => 'x86_64',
            'arm64', 'aarch64' => 'aarch64',
            default => null,
        };

        if ($archKey === null) {
            return null;
        }

        return match ($osFamily) {
            'Linux' => "dw-linux-{$archKey}",
            'Darwin' => $archKey === 'aarch64' ? 'dw-macos-aarch64' : null,
            'Windows', 'WINNT' => $archKey === 'x86_64' ? 'dw-windows-x86_64.exe' : null,
            default => null,
        };
    }

    private static function looksLikePhar(string $path): bool
    {
        return (bool) preg_match('/\.phar$/i', $path);
    }

    private static function isInsideVendor(string $path): bool
    {
        $normalized = str_replace('\\', '/', $path);

        return str_contains($normalized, '/vendor/');
    }

    private static function isInsideHomebrew(string $path): bool
    {
        $normalized = str_replace('\\', '/', $path);

        return str_contains($normalized, '/Cellar/')
            || str_contains($normalized, '/opt/homebrew/')
            || str_contains($normalized, '/linuxbrew/');
    }
}
