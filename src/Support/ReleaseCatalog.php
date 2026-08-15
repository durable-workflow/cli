<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Support;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Resolves release metadata for the standalone `dw` binary.
 *
 * Default discovery follows the passing public artifact compatibility
 * authority. Asset downloads and `SHA256SUMS` then use the exact qualified tag
 * rather than GitHub's stable-only /releases/latest route.
 */
final class ReleaseCatalog
{
    public const DEFAULT_REPO = 'durable-workflow/cli';

    public const DEFAULT_AUTHORITY_URL = 'https://durable-workflow.com/public-artifact-compatibility-evidence.json';

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
        $authorityUrl ??= getenv('DURABLE_WORKFLOW_QUALIFIED_AUTHORITY_URL') ?: self::DEFAULT_AUTHORITY_URL;

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
     * Resolve the supported release tag from the qualified artifact authority.
     */
    public function supportedTag(): string
    {
        try {
            $response = $this->http->request('GET', $this->authorityUrl);
            $data = $response->toArray();
        } catch (HttpExceptionInterface $e) {
            throw new ReleaseCatalogException(
                message: sprintf(
                    'could not fetch the qualified CLI release authority: %s',
                    $e->getMessage(),
                ),
                previous: $e,
            );
        }

        if (
            ($data['schema'] ?? null) !== 'durable-workflow.docs.public-artifact-compatibility-evidence'
            || ($data['schema_version'] ?? null) !== 2
            || ($data['outcome'] ?? null) !== 'pass'
        ) {
            throw new ReleaseCatalogException('qualified CLI release authority must be a passing schema-v2 document');
        }
        $tag = $data['qualified_artifact_versions']['cli'] ?? null;
        if (! is_string($tag) || $tag === '') {
            throw new ReleaseCatalogException('qualified CLI release authority must include a CLI version');
        }
        $tag = ltrim($tag, 'v');

        $stablePattern = '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/D';
        $prereleasePattern = '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)'.
            '-(alpha|beta|rc)\.(0|[1-9][0-9]*)$/D';
        if (preg_match($stablePattern, $tag) !== 1 && preg_match($prereleasePattern, $tag) !== 1) {
            throw new ReleaseCatalogException('qualified CLI release must name a stable, alpha, beta, or rc version');
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
