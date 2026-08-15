<?php

declare(strict_types=1);

namespace Tests\Support;

use DurableWorkflow\Cli\Support\ReleaseCatalog;
use DurableWorkflow\Cli\Support\ReleaseCatalogException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class ReleaseCatalogTest extends TestCase
{
    public function test_supported_tag_resolves_qualified_prerelease(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'schema' => 'durable-workflow.docs.public-artifact-compatibility-evidence',
                'schema_version' => 2,
                'outcome' => 'pass',
                'qualified_artifact_versions' => ['cli' => '2.0.0-rc.31'],
            ], JSON_THROW_ON_ERROR), [
                'http_code' => 200,
                'response_headers' => ['Content-Type: application/json'],
            ]),
        ]);
        $catalog = new ReleaseCatalog($client, 'durable-workflow/cli');

        self::assertSame('2.0.0-rc.31', $catalog->supportedTag());
    }

    public function test_supported_tag_accepts_authorized_stable_transition(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'schema' => 'durable-workflow.docs.public-artifact-compatibility-evidence',
                'schema_version' => 2,
                'outcome' => 'pass',
                'qualified_artifact_versions' => ['cli' => '2.0.0'],
            ], JSON_THROW_ON_ERROR), [
                'http_code' => 200,
                'response_headers' => ['Content-Type: application/json'],
            ]),
        ]);
        $catalog = new ReleaseCatalog($client, 'durable-workflow/cli');

        self::assertSame('2.0.0', $catalog->supportedTag());
    }

    public function test_supported_tag_rejects_unqualified_version_shape(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'schema' => 'durable-workflow.docs.public-artifact-compatibility-evidence',
                'schema_version' => 2,
                'outcome' => 'pass',
                'qualified_artifact_versions' => ['cli' => '2.0.0-preview.1'],
            ], JSON_THROW_ON_ERROR), [
                'http_code' => 200,
                'response_headers' => ['Content-Type: application/json'],
            ]),
        ]);
        $catalog = new ReleaseCatalog($client, 'durable-workflow/cli');

        $this->expectException(ReleaseCatalogException::class);
        $this->expectExceptionMessage('must name a stable, alpha, beta, or rc version');
        $catalog->supportedTag();
    }

    public function test_supported_tag_throws_when_authority_is_unavailable(): void
    {
        $client = new MockHttpClient([
            new MockResponse('', ['http_code' => 404]),
        ]);
        $catalog = new ReleaseCatalog($client, 'durable-workflow/cli');

        $this->expectException(ReleaseCatalogException::class);
        $this->expectExceptionMessage('could not fetch the qualified CLI release authority');
        $catalog->supportedTag();
    }

    public function test_download_url_formats_paths_without_leading_v(): void
    {
        $catalog = new ReleaseCatalog(new MockHttpClient(), 'durable-workflow/cli');

        self::assertSame(
            'https://github.com/durable-workflow/cli/releases/download/0.1.7/dw-linux-x86_64',
            $catalog->downloadUrl('v0.1.7', 'dw-linux-x86_64'),
        );
        self::assertSame(
            'https://github.com/durable-workflow/cli/releases/download/0.1.7/SHA256SUMS',
            $catalog->downloadUrl('0.1.7', 'SHA256SUMS'),
        );
    }

    public function test_fetch_returns_body_on_success(): void
    {
        $client = new MockHttpClient([
            new MockResponse('hello world', ['http_code' => 200]),
        ]);
        $catalog = new ReleaseCatalog($client, 'durable-workflow/cli');

        self::assertSame('hello world', $catalog->fetch('https://example.invalid/asset'));
    }

    public function test_fetch_throws_on_404(): void
    {
        $client = new MockHttpClient([
            new MockResponse('', ['http_code' => 404]),
        ]);
        $catalog = new ReleaseCatalog($client, 'durable-workflow/cli');

        $this->expectException(ReleaseCatalogException::class);
        $this->expectExceptionMessage('asset not found');
        $catalog->fetch('https://example.invalid/asset');
    }

    public function test_lookup_checksum_finds_matching_asset(): void
    {
        $sums = <<<'TXT'
abcd1234abcd1234abcd1234abcd1234abcd1234abcd1234abcd1234abcd1234  dw-linux-x86_64
deadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef *dw-macos-aarch64
TXT;

        self::assertSame(
            'abcd1234abcd1234abcd1234abcd1234abcd1234abcd1234abcd1234abcd1234',
            ReleaseCatalog::lookupChecksum($sums, 'dw-linux-x86_64'),
        );
        self::assertSame(
            'deadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef',
            ReleaseCatalog::lookupChecksum($sums, 'dw-macos-aarch64'),
        );
    }

    public function test_lookup_checksum_throws_when_missing(): void
    {
        $this->expectException(ReleaseCatalogException::class);
        $this->expectExceptionMessage('checksum for dw-linux-x86_64 not found');

        ReleaseCatalog::lookupChecksum(
            "deadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef  something-else\n",
            'dw-linux-x86_64',
        );
    }
}
