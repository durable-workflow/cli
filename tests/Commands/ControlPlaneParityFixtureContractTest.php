<?php

declare(strict_types=1);

namespace Tests\Commands;

use PHPUnit\Framework\TestCase;

final class ControlPlaneParityFixtureContractTest extends TestCase
{
    public function test_control_plane_parity_fixtures_are_versioned_contracts(): void
    {
        $paths = glob(__DIR__.'/../fixtures/control-plane/*-parity.json');
        self::assertIsArray($paths);
        self::assertNotEmpty($paths);

        foreach ($paths as $path) {
            $contents = file_get_contents($path);
            self::assertIsString($contents);

            $fixture = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($fixture);

            $file = basename($path);
            self::assertSame(
                'durable-workflow.polyglot.control-plane-request-fixture',
                $fixture['schema'] ?? null,
                "{$file} must declare the shared control-plane parity fixture schema.",
            );
            self::assertSame(
                1,
                $fixture['version'] ?? null,
                "{$file} must declare fixture contract version 1.",
            );
            self::assertNotSame(
                '',
                (string) ($fixture['operation'] ?? ''),
                "{$file} must name the operation.",
            );
            self::assertIsArray($fixture['request'] ?? null, "{$file} must declare the request shape.");
            self::assertNotSame(
                '',
                (string) ($fixture['request']['method'] ?? ''),
                "{$file} must declare request.method.",
            );
            self::assertStringStartsWith(
                '/',
                (string) ($fixture['request']['path'] ?? ''),
                "{$file} must declare request.path.",
            );
            self::assertIsArray($fixture['semantic_body'] ?? null, "{$file} must declare semantic_body.");
            self::assertIsArray($fixture['cli'] ?? null, "{$file} must declare the CLI projection.");
            self::assertIsArray($fixture['sdk_python'] ?? null, "{$file} must declare the Python SDK projection.");
        }
    }
}
