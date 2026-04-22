<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Support;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Resolves release metadata for the standalone `dw` binary.
 *
 * The catalog intentionally uses the unauthenticated
 * https://github.com/<repo>/releases redirect endpoints rather than the
 * GitHub REST API so CI runners without `GITHUB_TOKEN` do not hit the
 * 60-request/hour anonymous rate limit. The "latest" redirect resolves
 * to the exact tag; asset downloads and `SHA256SUMS` flow through the
 * same `github.com/<repo>/releases/download/<tag>/...` base URL.
 */
final class ReleaseCatalog
{
    public const DEFAULT_REPO = 'durable-workflow/cli';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $repo = self::DEFAULT_REPO,
        private readonly string $baseUrl = 'https://github.com',
    ) {
    }

    public static function create(
        ?HttpClientInterface $http = null,
        ?string $repo = null,
        ?string $baseUrl = null,
    ): self {
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
        );
    }

    /**
     * Resolve the latest published tag by following the canonical
     * /releases/latest redirect. Returns the bare tag (no leading "v").
     */
    public function latestTag(): string
    {
        $url = rtrim($this->baseUrl, '/')."/{$this->repo}/releases/latest";

        try {
            $response = $this->http->request('HEAD', $url);
            $status = $response->getStatusCode();
        } catch (HttpExceptionInterface $e) {
            throw new ReleaseCatalogException(
                message: sprintf('could not reach GitHub releases: %s', $e->getMessage()),
                previous: $e,
            );
        }

        if ($status >= 300 && $status < 400) {
            $location = $this->firstHeader($response->getHeaders(false), 'location');
            if ($location === null) {
                throw new ReleaseCatalogException('redirect from GitHub releases did not include a Location header');
            }

            $tag = basename(parse_url($location, PHP_URL_PATH) ?: $location);
            if ($tag === '' || $tag === 'latest') {
                throw new ReleaseCatalogException(sprintf('could not extract a release tag from %s', $location));
            }

            return ltrim($tag, 'v');
        }

        if ($status === 200) {
            // Some proxies follow redirects silently; fall back to the
            // GitHub API for a deterministic tag in that case.
            return $this->latestTagViaApi();
        }

        if ($status === 404) {
            throw new ReleaseCatalogException(sprintf('no published releases found for %s', $this->repo));
        }

        throw new ReleaseCatalogException(sprintf(
            'unexpected HTTP %d from GitHub releases for %s',
            $status,
            $this->repo,
        ));
    }

    private function latestTagViaApi(): string
    {
        $apiUrl = "https://api.github.com/repos/{$this->repo}/releases/latest";

        try {
            $response = $this->http->request('GET', $apiUrl);
            $data = $response->toArray();
        } catch (HttpExceptionInterface $e) {
            throw new ReleaseCatalogException(
                message: sprintf('could not fetch release metadata from %s: %s', $apiUrl, $e->getMessage()),
                previous: $e,
            );
        }

        $tag = $data['tag_name'] ?? null;
        if (! is_string($tag) || $tag === '') {
            throw new ReleaseCatalogException('release metadata response did not include a tag_name');
        }

        return ltrim($tag, 'v');
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

    /**
     * @param  array<string, array<int, string>>  $headers
     */
    private function firstHeader(array $headers, string $name): ?string
    {
        $name = strtolower($name);
        foreach ($headers as $key => $values) {
            if (strtolower((string) $key) === $name) {
                return is_array($values) ? (string) ($values[0] ?? '') : (string) $values;
            }
        }

        return null;
    }
}
