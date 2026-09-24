<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Support;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Resolves release metadata for the standalone `dw` binary.
 *
 * Default discovery follows GitHub's latest published stable release.
 * Asset downloads and `SHA256SUMS` use that exact tag.
 */
final class ReleaseCatalog
{
    public const DEFAULT_REPO = 'durable-workflow/cli';

    public const DEFAULT_AUTHORITY_URL = 'https://api.github.com/repos/durable-workflow/cli/releases/latest';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $repo = self::DEFAULT_REPO,
        private readonly string $baseUrl = 'https://github.com',
        private readonly string $authorityUrl = self::DEFAULT_AUTHORITY_URL,
    ) {
    }

    public static function create(
        ?HttpClientInterface $http = null,
        ?string $repo = null,
        ?string $baseUrl = null,
        ?string $authorityUrl = null,
    ): self {
        $authorityUrl ??= getenv('DURABLE_WORKFLOW_RELEASE_API_URL') ?: self::DEFAULT_AUTHORITY_URL;

        return new self(
            http: $http ?? HttpClient::create([
                'headers' => [
                    'Accept' => 'application/vnd.github+json, */*',
                    'User-Agent' => 'dw-cli-upgrade',
                ],
                'timeout' => 30,
                'max_redirects' => 0,
            ]),
            repo: $repo ?? self::DEFAULT_REPO,
            baseUrl: $baseUrl ?? 'https://github.com',
            authorityUrl: $authorityUrl,
        );
    }

    /**
     * Resolve the latest published stable release tag.
     */
    public function supportedTag(): string
    {
        try {
            $response = $this->http->request('GET', $this->authorityUrl);
            $data = $response->toArray();
        } catch (HttpExceptionInterface $e) {
            throw new ReleaseCatalogException(
                message: sprintf(
                    'could not fetch the latest stable CLI release: %s',
                    $e->getMessage(),
                ),
                previous: $e,
            );
        }

        $tag = $data['tag_name'] ?? null;
        if (! is_string($tag) || $tag === '') {
            throw new ReleaseCatalogException('latest stable CLI release must include a tag_name');
        }
        $tag = ltrim($tag, 'v');

        $stablePattern = '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/D';
        if (($data['draft'] ?? null) !== false || ($data['prerelease'] ?? null) !== false
            || preg_match($stablePattern, $tag) !== 1) {
            throw new ReleaseCatalogException('latest CLI release must be a published stable version');
        }

        return $tag;
    }

    public function downloadUrl(string $tag, string $asset): string
    {
        $tag = ltrim($tag, 'v');

        return rtrim($this->baseUrl, '/')."/{$this->repo}/releases/download/{$tag}/{$asset}";
    }

    public function fetch(string $url): string
    {
        try {
            $response = $this->http->request('GET', $url, [
                'max_redirects' => 5,
            ]);
            $status = $response->getStatusCode();
            if ($status === 404) {
                throw new ReleaseCatalogException(sprintf('asset not found: %s', $url));
            }
            if ($status >= 400) {
                throw new ReleaseCatalogException(sprintf('HTTP %d fetching %s', $status, $url));
            }

            return $response->getContent();
        } catch (HttpExceptionInterface $e) {
            throw new ReleaseCatalogException(
                message: sprintf('failed to download %s: %s', $url, $e->getMessage()),
                previous: $e,
            );
        }
    }

    /**
     * Parse a `SHA256SUMS` file and return the expected hash for the
     * requested asset. Supports the common `<hash>  <name>` and
     * `<hash> *<name>` formats.
     */
    public static function lookupChecksum(string $sums, string $asset): string
    {
        foreach (preg_split('/\r\n|\n|\r/', $sums) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (! preg_match('/^([0-9a-fA-F]{64})\s+\*?(.+)$/', $line, $m)) {
                continue;
            }
            if (trim($m[2]) === $asset) {
                return strtolower($m[1]);
            }
        }

        throw new ReleaseCatalogException(sprintf('checksum for %s not found in SHA256SUMS', $asset));
    }
}
