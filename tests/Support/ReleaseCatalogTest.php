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
    public function test_latest_tag_follows_redirect_to_tag_name(): void
    {
        $client = new MockHttpClient([
            new MockResponse('', [
                'http_code' => 302,
                'response_headers' => [
                    'Location: https://github.com/durable-workflow/cli/releases/tag/0.1.7',
                ],
            ]),
        ]);
        $catalog = new ReleaseCatalog($client, 'durable-workflow/cli');

        self::assertSame('0.1.7', $catalog->latestTag());
    }

    public function test_latest_tag_strips_leading_v_prefix(): void
    {
        $client = new MockHttpClient([
            new MockResponse('', [
                'http_code' => 302,
                'response_headers' => [
                    'Location: https://github.com/durable-workflow/cli/releases/tag/v0.2.0-beta.1',
                ],
            ]),
        ]);
        $catalog = new ReleaseCatalog($client, 'durable-workflow/cli');

        self::assertSame('0.2.0-beta.1', $catalog->latestTag());
    }

    public function test_latest_tag_falls_back_to_rest_api_when_redirect_is_followed(): void
    {
        $client = new MockHttpClient([
            new MockResponse('{"tag_name":"0.1.8"}', [
                'http_code' => 200,
                'response_headers' => ['Content-Type: application/json'],
            ]),
            new MockResponse('{"tag_name":"v0.1.8"}', [
                'http_code' => 200,
                'response_headers' => ['Content-Type: application/json'],
            ]),
        ]);
        $catalog = new ReleaseCatalog($client, 'durable-workflow/cli');

        self::assertSame('0.1.8', $catalog->latestTag());
    }

    public function test_latest_tag_throws_when_no_releases(): void
    {
        $client = new MockHttpClient([
            new MockResponse('', ['http_code' => 404]),
        ]);
        $catalog = new ReleaseCatalog($client, 'durable-workflow/cli');

        $this->expectException(ReleaseCatalogException::class);
        $this->expectExceptionMessage('no published releases found');
        $catalog->latestTag();
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
