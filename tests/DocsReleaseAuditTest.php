<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class DocsReleaseAuditTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryPaths = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->temporaryPaths) as $path) {
            if (is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                @rmdir($path);
            }
        }

        $this->temporaryPaths = [];
    }

    public function test_stale_docs_tuple_is_advisory_when_a_handoff_is_requested(): void
    {
        $sandbox = $this->createSandbox();
        $audit = $this->writeAudit($sandbox, '0.1.92');
        $evidence = $sandbox.'/evidence.json';
        $handoff = $sandbox.'/handoff.json';

        $process = $this->runAudit($sandbox, $audit, $evidence, $handoff, 'advisory');

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('::warning title=Docs release-audit tuple stale::', $process->getErrorOutput());
        self::assertStringNotContainsString('::error title=Docs release-audit tuple stale::', $process->getErrorOutput());

        $evidencePayload = $this->readJson($evidence);
        self::assertSame('stale', $evidencePayload['outcome']);
        self::assertFalse($evidencePayload['publication_blocking']);
        self::assertSame('0.1.93', $evidencePayload['expected_version']);
        self::assertSame('0.1.92', $evidencePayload['actual_version']);

        $handoffPayload = $this->readJson($handoff);
        self::assertSame('durable-workflow.release.docs-artifact-tuple-handoff', $handoffPayload['schema']);
        self::assertSame('pipeline_ready_item', $handoffPayload['action']);
        self::assertSame('durable-workflow.github.io', $handoffPayload['repository']);
        self::assertSame('0.1.93', $handoffPayload['stale_artifact']['expected_version']);
        self::assertSame('0.1.92', $handoffPayload['stale_artifact']['live_version']);
        self::assertSame(
            'https://github.com/durable-workflow/cli/actions/runs/1234',
            $handoffPayload['source_release_check']['run_url'],
        );
    }

    public function test_stale_docs_tuple_remains_blocking_without_advisory_mode(): void
    {
        $sandbox = $this->createSandbox();
        $audit = $this->writeAudit($sandbox, '0.1.92');
        $evidence = $sandbox.'/evidence.json';
        $handoff = $sandbox.'/handoff.json';

        $process = $this->runAudit($sandbox, $audit, $evidence, $handoff);

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('::error title=Docs release-audit tuple stale::', $process->getErrorOutput());
        self::assertTrue($this->readJson($evidence)['publication_blocking']);
    }

    public function test_older_release_replay_does_not_request_a_docs_tuple_downgrade(): void
    {
        $sandbox = $this->createSandbox();
        $audit = $this->writeAudit($sandbox, '0.1.94');
        $evidence = $sandbox.'/evidence.json';
        $handoff = $sandbox.'/handoff.json';

        $process = $this->runAudit($sandbox, $audit, $evidence, $handoff, 'advisory');

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString(
            'the replayed 0.1.93 release is superseded and must not request a docs tuple refresh',
            $process->getOutput(),
        );
        self::assertStringNotContainsString('Docs release-audit tuple stale', $process->getErrorOutput());
        self::assertFileDoesNotExist($handoff);

        $evidencePayload = $this->readJson($evidence);
        self::assertSame('superseded', $evidencePayload['outcome']);
        self::assertSame('0.1.93', $evidencePayload['expected_version']);
        self::assertSame('0.1.94', $evidencePayload['actual_version']);
        self::assertFalse($evidencePayload['publication_blocking']);
        self::assertArrayNotHasKey('docs_refresh_request', $evidencePayload);
        self::assertArrayNotHasKey('docs_artifact_tuple_handoff', $evidencePayload);
    }

    public function test_exact_docs_tuple_passes_without_a_handoff(): void
    {
        $sandbox = $this->createSandbox();
        $audit = $this->writeAudit($sandbox, '0.1.93');
        $evidence = $sandbox.'/evidence.json';
        $handoff = $sandbox.'/handoff.json';

        $process = $this->runAudit($sandbox, $audit, $evidence, $handoff, 'advisory');

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString(
            'confirms artifact_versions.cli=0.1.93',
            $process->getOutput(),
        );
        self::assertStringNotContainsString('Docs release-audit tuple stale', $process->getErrorOutput());
        self::assertFileDoesNotExist($handoff);

        $evidencePayload = $this->readJson($evidence);
        self::assertSame('pass', $evidencePayload['outcome']);
        self::assertSame('0.1.93', $evidencePayload['expected_version']);
        self::assertSame('0.1.93', $evidencePayload['actual_version']);
        self::assertArrayNotHasKey('docs_refresh_request', $evidencePayload);
        self::assertArrayNotHasKey('docs_artifact_tuple_handoff', $evidencePayload);
    }

    public function test_newer_numeric_prerelease_does_not_request_a_docs_tuple_downgrade(): void
    {
        $sandbox = $this->createSandbox();
        $audit = $this->writeAudit($sandbox, '2.0.0-alpha.138');
        $evidence = $sandbox.'/evidence.json';
        $handoff = $sandbox.'/handoff.json';

        $process = $this->runAudit(
            $sandbox,
            $audit,
            $evidence,
            $handoff,
            'advisory',
            '2.0.0-alpha.137',
        );

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString(
            'the replayed 2.0.0-alpha.137 release is superseded and must not request a docs tuple refresh',
            $process->getOutput(),
        );
        self::assertStringNotContainsString('Docs release-audit tuple stale', $process->getErrorOutput());
        self::assertFileDoesNotExist($handoff);

        $evidencePayload = $this->readJson($evidence);
        self::assertSame('superseded', $evidencePayload['outcome']);
        self::assertSame('2.0.0-alpha.137', $evidencePayload['expected_version']);
        self::assertSame('2.0.0-alpha.138', $evidencePayload['actual_version']);
        self::assertFalse($evidencePayload['publication_blocking']);
        self::assertArrayNotHasKey('docs_refresh_request', $evidencePayload);
        self::assertArrayNotHasKey('docs_artifact_tuple_handoff', $evidencePayload);
    }

    public function test_older_numeric_prerelease_remains_stale_and_requests_a_handoff(): void
    {
        $sandbox = $this->createSandbox();
        $audit = $this->writeAudit($sandbox, '2.0.0-alpha.136');
        $evidence = $sandbox.'/evidence.json';
        $handoff = $sandbox.'/handoff.json';

        $process = $this->runAudit(
            $sandbox,
            $audit,
            $evidence,
            $handoff,
            'advisory',
            '2.0.0-alpha.137',
        );

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('::warning title=Docs release-audit tuple stale::', $process->getErrorOutput());

        $evidencePayload = $this->readJson($evidence);
        self::assertSame('stale', $evidencePayload['outcome']);
        self::assertSame('2.0.0-alpha.137', $evidencePayload['expected_version']);
        self::assertSame('2.0.0-alpha.136', $evidencePayload['actual_version']);
        self::assertFalse($evidencePayload['publication_blocking']);

        $handoffPayload = $this->readJson($handoff);
        self::assertSame('2.0.0-alpha.137', $handoffPayload['stale_artifact']['expected_version']);
        self::assertSame('2.0.0-alpha.136', $handoffPayload['stale_artifact']['live_version']);
    }

    #[DataProvider('staleModes')]
    public function test_invalid_advertised_version_is_a_hard_failure_without_a_handoff(?string $staleMode): void
    {
        $sandbox = $this->createSandbox();
        $audit = $this->writeAudit($sandbox, 'not-a-version');
        $evidence = $sandbox.'/evidence.json';
        $handoff = $sandbox.'/handoff.json';

        $process = $this->runAudit($sandbox, $audit, $evidence, $handoff, $staleMode);

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString(
            'reports invalid artifact_versions.cli=not-a-version',
            $process->getErrorOutput(),
        );
        self::assertSame('unavailable', $this->readJson($evidence)['outcome']);
        self::assertFileDoesNotExist($handoff);
    }

    /** @return iterable<string, array{?string}> */
    public static function staleModes(): iterable
    {
        yield 'blocking' => [null];
        yield 'advisory' => ['advisory'];
    }

    public function test_advisory_mode_requires_an_uploadable_handoff(): void
    {
        $sandbox = $this->createSandbox();
        $audit = $this->writeAudit($sandbox, '0.1.92');
        $evidence = $sandbox.'/evidence.json';

        $process = $this->runAudit($sandbox, $audit, $evidence, '', 'advisory');

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('DOCS_RELEASE_AUDIT_HANDOFF is required', $process->getErrorOutput());
    }

    public function test_advisory_mode_does_not_hide_an_invalid_audit_response(): void
    {
        $sandbox = $this->createSandbox();
        $audit = $sandbox.'/audit.json';
        self::assertNotFalse(file_put_contents($audit, '{"schema":"unexpected"}'));
        $this->temporaryPaths[] = $audit;
        $evidence = $sandbox.'/evidence.json';
        $handoff = $sandbox.'/handoff.json';

        $process = $this->runAudit($sandbox, $audit, $evidence, $handoff, 'advisory');

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('Docs release-audit unavailable', $process->getErrorOutput());
        self::assertSame('unavailable', $this->readJson($evidence)['outcome']);
        self::assertFileDoesNotExist($handoff);
    }

    private function createSandbox(): string
    {
        $sandbox = sys_get_temp_dir().'/cli-docs-release-audit-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($sandbox));
        $this->temporaryPaths[] = $sandbox;

        $fakeCurl = $sandbox.'/curl';
        self::assertNotFalse(file_put_contents($fakeCurl, <<<'SH'
#!/usr/bin/env sh
set -eu

output=''
while [ "$#" -gt 0 ]; do
    case "$1" in
        -o)
            output="$2"
            shift 2
            ;;
        *)
            shift
            ;;
    esac
done

[ -n "$output" ]
cp "$FAKE_AUDIT_SOURCE" "$output"
SH));
        self::assertTrue(chmod($fakeCurl, 0755));
        $this->temporaryPaths[] = $fakeCurl;

        return $sandbox;
    }

    private function writeAudit(string $sandbox, string $cliVersion): string
    {
        $path = $sandbox.'/audit.json';
        self::assertNotFalse(file_put_contents($path, json_encode([
            'schema' => 'durable-workflow.docs.page-release-audit',
            'artifact_versions' => [
                'cli' => $cliVersion,
                'server' => '2.0.0-beta.9',
            ],
        ], JSON_THROW_ON_ERROR)));
        $this->temporaryPaths[] = $path;

        return $path;
    }

    private function runAudit(
        string $sandbox,
        string $audit,
        string $evidence,
        string $handoff,
        ?string $staleMode = null,
        string $expectedVersion = '0.1.93',
    ): Process {
        $summary = $sandbox.'/summary.md';
        $this->temporaryPaths[] = $summary;
        $this->temporaryPaths[] = $evidence;
        if ($handoff !== '') {
            $this->temporaryPaths[] = $handoff;
        }

        $environment = [
            'PATH' => $sandbox.':'.getenv('PATH'),
            'FAKE_AUDIT_SOURCE' => $audit,
            'DOCS_RELEASE_AUDIT_ARTIFACT' => 'cli',
            'DOCS_RELEASE_AUDIT_VERSION' => $expectedVersion,
            'DOCS_RELEASE_AUDIT_URL' => 'https://docs.example.invalid/release-audit.json',
            'DOCS_RELEASE_AUDIT_ATTEMPTS' => '1',
            'DOCS_RELEASE_AUDIT_RETRY_SLEEP' => '0',
            'DOCS_RELEASE_AUDIT_EVIDENCE' => $evidence,
            'DOCS_RELEASE_AUDIT_HANDOFF' => $handoff,
            'GITHUB_STEP_SUMMARY' => $summary,
            'GITHUB_SERVER_URL' => 'https://github.com',
            'GITHUB_REPOSITORY' => 'durable-workflow/cli',
            'GITHUB_REF_NAME' => $expectedVersion,
            'GITHUB_SHA' => str_repeat('a', 40),
            'GITHUB_RUN_ID' => '1234',
            'GITHUB_RUN_ATTEMPT' => '1',
        ];
        if ($staleMode !== null) {
            $environment['DOCS_RELEASE_AUDIT_STALE_MODE'] = $staleMode;
        }

        $process = new Process(
            [dirname(__DIR__).'/scripts/ci/check-docs-release-audit.sh'],
            dirname(__DIR__),
            $environment,
        );
        $process->run();

        return $process;
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        $contents = file_get_contents($path);
        self::assertIsString($contents);
        $payload = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }
}
