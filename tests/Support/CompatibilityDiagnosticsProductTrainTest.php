<?php

declare(strict_types=1);

namespace Tests\Support;

use DurableWorkflow\Cli\Support\CompatibilityDiagnostics;
use PHPUnit\Framework\TestCase;

final class CompatibilityDiagnosticsProductTrainTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function clusterInfo(string $constraint): array
    {
        return [
            'client_compatibility' => [
                'clients' => [
                    'cli' => ['supported_versions' => $constraint],
                ],
            ],
        ];
    }

    public function testProductTrainPrereleaseFloorRejectsEarlierBeta(): void
    {
        $clusterInfo = $this->clusterInfo('>=2.0.0-beta.6,<2.0.0-beta.7');

        self::assertFalse(CompatibilityDiagnostics::cliVersionIsSupported($clusterInfo, '2.0.0-beta.1'));
        self::assertTrue(CompatibilityDiagnostics::cliVersionIsSupported($clusterInfo, '2.0.0-beta.6'));
        self::assertFalse(CompatibilityDiagnostics::cliVersionIsSupported($clusterInfo, '2.0.0-beta.7'));
        self::assertFalse(CompatibilityDiagnostics::cliVersionIsSupported($clusterInfo, '2.0.0'));
    }
}
