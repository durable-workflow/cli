<?php

declare(strict_types=1);

namespace Tests\Support;

use DurableWorkflow\Cli\Support\ReleaseVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReleaseVersionTest extends TestCase
{
    #[DataProvider('orderedVersions')]
    public function test_compares_semver_release_precedence(string $older, string $newer): void
    {
        self::assertSame(-1, ReleaseVersion::compare($older, $newer));
        self::assertSame(1, ReleaseVersion::compare($newer, $older));
    }

    public static function orderedVersions(): iterable
    {
        yield 'major' => ['1.99.99', '2.0.0-alpha.1'];
        yield 'minor' => ['2.9.9', '2.10.0'];
        yield 'patch' => ['2.0.9', '2.0.10'];
        yield 'alpha numeric sequence' => ['2.0.0-alpha.9', '2.0.0-alpha.10'];
        yield 'alpha to beta' => ['2.0.0-alpha.99', '2.0.0-beta.1'];
        yield 'beta numeric sequence' => ['2.0.0-beta.9', '2.0.0-beta.10'];
        yield 'beta to rc' => ['2.0.0-beta.99', '2.0.0-rc.1'];
        yield 'rc numeric sequence' => ['2.0.0-rc.9', '2.0.0-rc.10'];
        yield 'prerelease to stable' => ['2.0.0-rc.99', '2.0.0'];
    }

    public function test_build_metadata_does_not_change_precedence(): void
    {
        self::assertSame(0, ReleaseVersion::compare('2.0.0+build.1', '2.0.0+build.2'));
    }

    public function test_rejects_non_semver_input(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ReleaseVersion::compare('2.0-dev', '2.0.0');
    }
}
