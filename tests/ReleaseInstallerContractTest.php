<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ReleaseInstallerContractTest extends TestCase
{
    public function test_installers_are_versioned_release_assets(): void
    {
        $releaseWorkflow = self::readRepoFile('.github/workflows/release.yml');

        self::assertStringContainsString('Resolve release tag', $releaseWorkflow);
        self::assertStringContainsString('DISPATCH_TAG: ${{ inputs.tag }}', $releaseWorkflow);
        self::assertStringContainsString('0.0.1-test or v0.0.1-test', $releaseWorkflow);
        self::assertStringContainsString('raw_tag="$tag"', $releaseWorkflow);
        self::assertStringContainsString('node scripts/ci/release-version.js normalize "$raw_tag"', $releaseWorkflow);
        self::assertStringContainsString('ref: ${{ needs.resolve-release.outputs.commit }}', $releaseWorkflow);
        self::assertStringContainsString('DW_CLI_VERSION: ${{ needs.resolve-release.outputs.tag }}', $releaseWorkflow);
        self::assertStringContainsString('DW_CLI_COMMIT="$(git rev-parse HEAD)"', $releaseWorkflow);
        self::assertStringContainsString('release-preflight:', $releaseWorkflow);
        self::assertStringContainsString('public_assets_present: ${{ steps.public_assets.outputs.present }}', $releaseWorkflow);
        self::assertStringContainsString('echo "present=${present}" >> "$GITHUB_OUTPUT"', $releaseWorkflow);
        self::assertStringContainsString('existing-public-assets-rerun-gate', $releaseWorkflow);
        self::assertStringContainsString('pre-upload-public-asset-presence-check', $releaseWorkflow);
        self::assertStringContainsString('complete_public_asset_set: present === \'true\'', $releaseWorkflow);
        self::assertStringContainsString('Require live docs release audit for existing public assets', $releaseWorkflow);
        self::assertStringContainsString("if: steps.public_assets.outputs.present == 'true'", $releaseWorkflow);
        self::assertStringContainsString('DOCS_RELEASE_AUDIT_EVIDENCE: docs-release-audit-evidence.json', $releaseWorkflow);
        self::assertStringContainsString('DOCS_RELEASE_AUDIT_HANDOFF: docs-release-audit-handoff.json', $releaseWorkflow);
        self::assertSame(2, substr_count($releaseWorkflow, 'DOCS_RELEASE_AUDIT_STALE_MODE: advisory'));
        self::assertStringContainsString('release-preflight-public-assets-evidence.json', $releaseWorkflow);
        self::assertStringContainsString('docs-release-audit-handoff.json', $releaseWorkflow);
        self::assertStringContainsString('needs: [resolve-release, release-preflight]', $releaseWorkflow);
        self::assertStringContainsString("needs.release-preflight.result == 'success'", $releaseWorkflow);
        self::assertStringContainsString("needs.release-preflight.outputs.public_assets_present != 'true'", $releaseWorkflow);
        self::assertStringContainsString('cp scripts/install.sh dist/install.sh', $releaseWorkflow);
        self::assertStringContainsString('cp scripts/install.ps1 dist/install.ps1', $releaseWorkflow);
        self::assertStringContainsString('cp scripts/verify-release.sh dist/verify-release.sh', $releaseWorkflow);
        self::assertStringContainsString('tag_name: ${{ needs.resolve-release.outputs.tag }}', $releaseWorkflow);
        self::assertStringContainsString('Verify public release downloads', $releaseWorkflow);
        self::assertStringContainsString('public_asset_url()', $releaseWorkflow);
        self::assertStringContainsString('wait_for_asset()', $releaseWorkflow);
        self::assertStringContainsString('VERSION="$tag" DURABLE_WORKFLOW_INSTALL_DIR="$install_dir"', $releaseWorkflow);
        self::assertStringNotContainsString('scripts/verify-public-release-assets.sh "${{ needs.resolve-release.outputs.tag }}"', $releaseWorkflow);
        self::assertStringNotContainsString('continue-on-error: true', $releaseWorkflow);
        self::assertStringContainsString('fail_on_unmatched_files: true', $releaseWorkflow);
        self::assertStringContainsString('Missing required release artifact: $artifact', $releaseWorkflow);
        self::assertStringContainsString('Public release asset is not downloadable: $url', $releaseWorkflow);
        self::assertStringContainsString("needs.build-binary-windows.result == 'success'", $releaseWorkflow);
        self::assertStringContainsString('dw-windows-x86_64.exe', $releaseWorkflow);
        self::assertStringContainsString('.\\build\\dw-windows-x86_64.exe runtime:check', $releaseWorkflow);
        self::assertStringContainsString('release-public-download-evidence.json', $releaseWorkflow);
        self::assertStringContainsString('"artifact_versions": {"cli": "%s"}', $releaseWorkflow);
        self::assertStringContainsString('"installable_artifacts": {"verified_public_downloads": true, "version": "%s"}', $releaseWorkflow);
        self::assertStringContainsString('Verify live docs release audit after public downloads', $releaseWorkflow);
        self::assertStringContainsString('name: release-evidence', $releaseWorkflow);
        self::assertStringNotContainsString('"docs_release_audit": {"artifact": "cli", "version": "%s", "checked_before_public_upload": true', $releaseWorkflow);
        self::assertStringContainsString('install.sh', $releaseWorkflow);
        self::assertStringContainsString('install.ps1', $releaseWorkflow);
        self::assertStringContainsString('verify-release.sh', $releaseWorkflow);
        self::assertStringContainsString('subject-path: dist/*', $releaseWorkflow);
        self::assertStringContainsString('Write release notes', $releaseWorkflow);
        self::assertStringContainsString('Durable Workflow CLI ${tag}', $releaseWorkflow);
        self::assertStringContainsString('SHA256SUMS for artifact verification', $releaseWorkflow);
        self::assertStringContainsString('body_path: release-notes.md', $releaseWorkflow);
        self::assertStringContainsString('SPC_DOWNLOAD_RETRY: \'5\'', $releaseWorkflow);
        self::assertStringContainsString('SPC_DOWNLOAD_OUTER_ATTEMPTS: \'4\'', $releaseWorkflow);
        self::assertStringContainsString('spc dependency download failed after ${SPC_DOWNLOAD_OUTER_ATTEMPTS} attempts', $releaseWorkflow);
        self::assertStringContainsString('spc dependency download failed after $outerAttempts attempts', $releaseWorkflow);
        self::assertStringContainsString('--without-suggestions --retry="${SPC_DOWNLOAD_RETRY}"', $releaseWorkflow);
        self::assertStringContainsString('--without-suggestions --retry="$env:SPC_DOWNLOAD_RETRY"', $releaseWorkflow);
        self::assertStringContainsString('name: ${{ matrix.name }}-spc-logs', $releaseWorkflow);
        self::assertStringNotContainsString('Require live docs release audit refresh', $releaseWorkflow);

        $preflightDocsGatePosition = strpos($releaseWorkflow, 'Require live docs release audit for existing public assets');
        $buildPosition = strpos($releaseWorkflow, 'build-phar:');
        $uploadPosition = strpos($releaseWorkflow, 'Create GitHub Release');
        $publicDownloadPosition = strpos($releaseWorkflow, 'Verify public release downloads');
        $postUploadDocsGatePosition = strpos($releaseWorkflow, 'Verify live docs release audit after public downloads');
        self::assertIsInt($preflightDocsGatePosition);
        self::assertIsInt($buildPosition);
        self::assertIsInt($uploadPosition);
        self::assertIsInt($publicDownloadPosition);
        self::assertIsInt($postUploadDocsGatePosition);
        self::assertLessThan($buildPosition, $preflightDocsGatePosition);
        self::assertLessThan($uploadPosition, $preflightDocsGatePosition);
        self::assertLessThan($postUploadDocsGatePosition, $publicDownloadPosition);
    }

    public function test_build_validates_installer_scripts(): void
    {
        $buildWorkflow = self::readRepoFile('.github/workflows/build.yml');

        self::assertStringContainsString('sh -n scripts/install.sh', $buildWorkflow);
        self::assertStringContainsString('sh -n scripts/generate-homebrew-formula.sh', $buildWorkflow);
        self::assertStringContainsString('sh -n scripts/verify-release.sh', $buildWorkflow);
        self::assertStringContainsString('bash -n scripts/verify-public-release-assets.sh', $buildWorkflow);
        self::assertStringContainsString('sh -n scripts/ci/check-docs-release-audit.sh', $buildWorkflow);
        self::assertStringContainsString('node --check scripts/ci/release-version.js', $buildWorkflow);
        self::assertStringContainsString('bash -n scripts/ci/verify-release-tag-source.sh', $buildWorkflow);
        self::assertStringContainsString('scripts/install.ps1', $buildWorkflow);
    }

    public function test_release_recovery_retains_the_planned_commit_at_publication(): void
    {
        $releaseWorkflow = self::readRepoFile('.github/workflows/release.yml');
        $recoveryWorkflow = self::readRepoFile('.github/workflows/release-plan-recovery.yml');

        self::assertStringContainsString('release_commit:', $releaseWorkflow);
        self::assertStringContainsString('commit: ${{ steps.resolve.outputs.commit }}', $releaseWorkflow);
        self::assertStringContainsString('DISPATCH_COMMIT: ${{ inputs.release_commit }}', $releaseWorkflow);
        self::assertStringContainsString('PUSH_COMMIT: ${{ github.sha }}', $releaseWorkflow);
        self::assertSame(5, substr_count($releaseWorkflow, 'ref: ${{ needs.resolve-release.outputs.commit }}'));
        self::assertStringNotContainsString('|| github.ref }}', $releaseWorkflow);
        self::assertStringContainsString('EXPECTED_COMMIT: ${{ needs.resolve-release.outputs.commit }}', $releaseWorkflow);
        self::assertSame(2, substr_count($releaseWorkflow, 'RELEASE_COMMIT: ${{ needs.resolve-release.outputs.commit }}'));
        self::assertStringContainsString('-f release_commit="$RELEASE_COMMIT"', $recoveryWorkflow);
        self::assertSame(2, substr_count($recoveryWorkflow, 'scripts/ci/verify-release-tag-source.sh'));

        $sourceCheck = strpos($releaseWorkflow, 'Resolve exact source identity');
        $boundaryCheck = strpos($releaseWorkflow, 'Verify immutable release tag at publication boundary');
        $attestation = strpos($releaseWorkflow, 'Attest release artifacts');
        $publication = strpos($releaseWorkflow, 'Create GitHub Release');
        self::assertIsInt($sourceCheck);
        self::assertIsInt($boundaryCheck);
        self::assertIsInt($attestation);
        self::assertIsInt($publication);
        self::assertLessThan($boundaryCheck, $sourceCheck);
        self::assertLessThan($attestation, $boundaryCheck);
        self::assertLessThan($publication, $boundaryCheck);
    }

    public function test_recovery_reused_push_run_rejects_tag_movement_before_publication(): void
    {
        $releaseWorkflow = self::readRepoFile('.github/workflows/release.yml');
        $plannedCommit = str_repeat('a', 40);
        $movedCommit = str_repeat('b', 40);
        $temporary = sys_get_temp_dir().'/cli-release-tag-'.bin2hex(random_bytes(4));
        self::assertTrue(mkdir($temporary));
        $fakeGh = $temporary.'/gh';
        $publicationRuns = $temporary.'/publication-runs.json';
        file_put_contents($fakeGh, <<<'SH'
#!/usr/bin/env sh
set -eu
printf 'commit %s\n' "$FAKE_TAG_SHA"
SH);
        self::assertTrue(chmod($fakeGh, 0755));
        file_put_contents($publicationRuns, json_encode([
            [
                'databaseId' => 1234,
                'displayTitle' => 'Release 1.2.3-alpha.4 for direct',
                'event' => 'push',
                'headBranch' => '1.2.3-alpha.4',
                'headSha' => $plannedCommit,
                'status' => 'in_progress',
                'conclusion' => null,
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertStringContainsString('PUSH_COMMIT: ${{ github.sha }}', $releaseWorkflow);
        self::assertSame(5, substr_count($releaseWorkflow, 'ref: ${{ needs.resolve-release.outputs.commit }}'));
        self::assertSame(2, substr_count($releaseWorkflow, 'RELEASE_COMMIT: ${{ needs.resolve-release.outputs.commit }}'));

        $environment = [
            'GH_CLI' => $fakeGh,
            'GITHUB_REPOSITORY' => 'durable-workflow/cli',
            'RELEASE_TAG' => '1.2.3-alpha.4',
            'RELEASE_COMMIT' => $plannedCommit,
        ];

        try {
            $selection = new Process([
                'python3',
                dirname(__DIR__).'/scripts/ci/component-release-recovery.py',
                'select-publication-run',
                '--release-tag',
                '1.2.3-alpha.4',
                '--release-commit',
                $plannedCommit,
                '--runs',
                $publicationRuns,
            ], dirname(__DIR__));
            self::assertSame(0, $selection->run(), $selection->getErrorOutput());
            self::assertSame("wait\t1234\tin_progress\t\n", $selection->getOutput());

            $exact = new Process(
                [dirname(__DIR__).'/scripts/ci/verify-release-tag-source.sh'],
                dirname(__DIR__),
                $environment + ['FAKE_TAG_SHA' => $plannedCommit],
            );
            self::assertSame(0, $exact->run(), $exact->getErrorOutput());

            $moved = new Process(
                [dirname(__DIR__).'/scripts/ci/verify-release-tag-source.sh'],
                dirname(__DIR__),
                $environment + ['FAKE_TAG_SHA' => $movedCommit],
            );
            self::assertSame(1, $moved->run());
            self::assertStringContainsString($movedCommit, $moved->getErrorOutput());
            self::assertStringContainsString($plannedCommit, $moved->getErrorOutput());
        } finally {
            @unlink($fakeGh);
            @unlink($publicationRuns);
            @rmdir($temporary);
        }
    }

    public function test_release_includes_checksum_and_attestation_verifier(): void
    {
        $verifier = self::readRepoFile('scripts/verify-release.sh');
        $publicAssetVerifier = self::readRepoFile('scripts/verify-public-release-assets.sh');

        self::assertStringContainsString('SHA256SUMS', $verifier);
        self::assertStringContainsString('sha256sum -c SHA256SUMS --ignore-missing', $verifier);
        self::assertStringContainsString('gh attestation verify', $verifier);
        self::assertStringContainsString('DURABLE_WORKFLOW_VERIFY_ATTESTATIONS', $verifier);
        self::assertStringContainsString('raw_tag="${1:-}"', $publicAssetVerifier);
        self::assertStringContainsString('release-version.js" normalize "$raw_tag"', $publicAssetVerifier);
        self::assertStringContainsString('releases/download/${tag}/${artifact}', $publicAssetVerifier);
        self::assertStringContainsString('curl -fsSLI --retry 3 --retry-all-errors', $publicAssetVerifier);
        self::assertStringContainsString('dw-windows-x86_64.exe', $publicAssetVerifier);
    }

    public function test_docs_release_audit_writes_preflight_evidence(): void
    {
        $auditor = self::readRepoFile('scripts/ci/check-docs-release-audit.sh');

        self::assertStringContainsString('DOCS_RELEASE_AUDIT_EVIDENCE', $auditor);
        self::assertStringContainsString('DOCS_RELEASE_AUDIT_HANDOFF', $auditor);
        self::assertStringContainsString('durable-workflow.release.docs-release-audit-evidence', $auditor);
        self::assertStringContainsString('durable-workflow.release.docs-artifact-tuple-handoff', $auditor);
        self::assertStringContainsString('docs-page-release-audit-${artifact}-${expected}-$$.json', $auditor);
        self::assertStringContainsString('trap \'rm -f "$audit_path"\' EXIT HUP INT TERM', $auditor);
        self::assertStringContainsString("surface: 'public_docs_release_audit'", $auditor);
        self::assertStringContainsString("outcome: 'unavailable'", $auditor);
        self::assertStringContainsString("writeEvidence('stale'", $auditor);
        self::assertStringContainsString("writeEvidence('pass'", $auditor);
        self::assertStringContainsString('actual_version: actualVersion', $auditor);
        self::assertStringContainsString("schema: 'durable-workflow.docs.refresh-request'", $auditor);
        self::assertStringContainsString("repository: 'durable-workflow.github.io'", $auditor);
        self::assertStringContainsString('refresh_command: refreshCommand', $auditor);
        self::assertStringContainsString('refresh_files: refreshFiles', $auditor);
        self::assertStringContainsString('observed_artifact_versions: versions', $auditor);
        self::assertStringContainsString('docs_refresh_request: docsRefreshRequest', $auditor);
        self::assertStringContainsString('docs_artifact_tuple_handoff: handoff', $auditor);
    }

    public function test_release_publishes_generated_homebrew_formula(): void
    {
        $releaseWorkflow = self::readRepoFile('.github/workflows/release.yml');
        $formulaGenerator = self::readRepoFile('scripts/generate-homebrew-formula.sh');

        self::assertStringContainsString('Generate Homebrew formula', $releaseWorkflow);
        self::assertStringContainsString('scripts/generate-homebrew-formula.sh dist "${{ needs.resolve-release.outputs.tag }}"', $releaseWorkflow);
        self::assertStringContainsString('tag="${tag#v}"', $formulaGenerator);
        self::assertStringContainsString('dw.rb', $formulaGenerator);
        self::assertStringContainsString('dw-macos-aarch64', $formulaGenerator);
        self::assertStringContainsString('class Dw < Formula', $formulaGenerator);
    }

    public function test_installers_verify_release_checksums_before_installing(): void
    {
        $shellInstaller = self::readRepoFile('scripts/install.sh');
        $powershellInstaller = self::readRepoFile('scripts/install.ps1');

        self::assertStringContainsString('SHA256SUMS', $shellInstaller);
        self::assertStringContainsString('checksum verification failed', $shellInstaller);
        self::assertStringContainsString('DURABLE_WORKFLOW_INSTALL_VERIFY_ATTESTATIONS', $shellInstaller);
        self::assertStringContainsString('release_version="${VERSION#v}"', $shellInstaller);
        self::assertStringContainsString('gh attestation verify "$tmp" --repo "$REPO"', $shellInstaller);
        self::assertStringContainsString('gh attestation verify "$sums" --repo "$REPO"', $shellInstaller);
        self::assertStringContainsString('mv "$tmp" "$INSTALL_DIR/$BIN_NAME"', $shellInstaller);

        self::assertStringContainsString('SHA256SUMS', $powershellInstaller);
        self::assertStringContainsString('Checksum verification failed', $powershellInstaller);
        self::assertStringContainsString('DURABLE_WORKFLOW_INSTALL_VERIFY_ATTESTATIONS', $powershellInstaller);
        self::assertStringContainsString('$releaseVersion = if ($version -ne \'latest\' -and $version.StartsWith(\'v\'))', $powershellInstaller);
        self::assertStringContainsString('gh attestation verify $tmp --repo $repo', $powershellInstaller);
        self::assertStringContainsString('gh attestation verify $sums --repo $repo', $powershellInstaller);
        self::assertStringContainsString('Move-Item -Force -Path $tmp -Destination $dest', $powershellInstaller);
    }

    public function test_release_runtime_check_pins_required_standalone_extensions(): void
    {
        $releaseWorkflow = self::readRepoFile('.github/workflows/release.yml');
        $runtimeCheck = self::readRepoFile('src/Commands/RuntimeCheckCommand.php');

        self::assertStringContainsString('STANDALONE_RUNTIME_EXTENSIONS: curl,mbstring,openssl,phar,tokenizer,ctype,filter,fileinfo,iconv,sockets', $releaseWorkflow);
        self::assertStringContainsString('STANDALONE_RUNTIME_EXTENSIONS_WINDOWS: mbstring,openssl,phar,tokenizer,ctype,filter,fileinfo,iconv,sockets', $releaseWorkflow);
        self::assertStringContainsString('SPC_EXTENSIONS_WINDOWS: mbstring,openssl,phar,tokenizer,ctype,filter,fileinfo,iconv,sockets', $releaseWorkflow);
        self::assertStringNotContainsString('SPC_EXTENSIONS_WINDOWS: curl', $releaseWorkflow);
        self::assertStringContainsString('Remove-Item -Recurse -Force source\php-src -ErrorAction SilentlyContinue', $releaseWorkflow);
        self::assertStringContainsString('php bin\spc extract php-src', $releaseWorkflow);
        self::assertStringContainsString('Patch PHP OpenSSL 3 compatibility', $releaseWorkflow);
        self::assertStringContainsString('php_openssl.h was not found after spc extract; continuing without local OpenSSL patch.', $releaseWorkflow);
        self::assertStringContainsString("public const REQUIRED_EXTENSIONS", $runtimeCheck);
        self::assertStringContainsString("public const REQUIRED_EXTENSIONS_WINDOWS", $runtimeCheck);
        self::assertStringContainsString("'curl'", $runtimeCheck);
        self::assertStringContainsString("'mbstring'", $runtimeCheck);
        self::assertStringContainsString("'openssl'", $runtimeCheck);
        self::assertStringContainsString("'sockets'", $runtimeCheck);
        self::assertStringContainsString("setHidden(true)", $runtimeCheck);
    }

    public function test_release_pipeline_pins_source_date_epoch(): void
    {
        $releaseWorkflow = self::readRepoFile('.github/workflows/release.yml');
        $buildScript = self::readRepoFile('scripts/build.sh');
        $generator = self::readRepoFile('scripts/generate-build-info.php');

        self::assertStringContainsString('SOURCE_DATE_EPOCH', $releaseWorkflow);
        self::assertStringContainsString('Pin SOURCE_DATE_EPOCH', $releaseWorkflow);
        self::assertStringContainsString('Normalize input mtimes', $releaseWorkflow);

        self::assertStringContainsString('SOURCE_DATE_EPOCH', $buildScript);
        self::assertStringContainsString('ensure_source_date_epoch', $buildScript);
        self::assertStringContainsString('normalize_mtimes', $buildScript);
        self::assertStringContainsString('SPC_DOWNLOAD_RETRY="${SPC_DOWNLOAD_RETRY:-5}"', $buildScript);
        self::assertStringContainsString('SPC_DOWNLOAD_OUTER_ATTEMPTS="${SPC_DOWNLOAD_OUTER_ATTEMPTS:-4}"', $buildScript);
        self::assertStringContainsString('spc_download_with_retry', $buildScript);
        self::assertStringContainsString('--prefer-pre-built --without-suggestions --retry="$SPC_DOWNLOAD_RETRY"', $buildScript);

        self::assertStringContainsString('SOURCE_DATE_EPOCH', $generator);
    }

    public function test_reproducible_build_verifier_is_present_and_wired(): void
    {
        $verifier = self::readRepoFile('scripts/verify-reproducible-build.sh');
        $buildWorkflow = self::readRepoFile('.github/workflows/build.yml');
        $composer = self::readRepoFile('composer.json');
        $box = self::readRepoFile('box.json.dist');

        self::assertStringContainsString('SOURCE_DATE_EPOCH', $verifier);
        self::assertStringContainsString('scripts/build.sh phar', $verifier);
        self::assertStringContainsString('mktemp -d', $verifier);
        self::assertStringContainsString('trap cleanup EXIT', $verifier);
        self::assertStringContainsString('git -C "$ROOT" archive', $verifier);
        self::assertStringContainsString('source.1', $verifier);
        self::assertStringContainsString('source.2', $verifier);
        self::assertStringNotContainsString('$ROOT/build/.repro', $verifier);
        self::assertStringContainsString('PHAR builds are not bit-identical', $verifier);
        self::assertStringContainsString('"autoloader-suffix": "DurableWorkflowCli"', $composer);
        self::assertStringContainsString('"alias": "dw.phar"', $box);

        self::assertStringContainsString('reproducible-build:', $buildWorkflow);
        self::assertStringContainsString('scripts/verify-reproducible-build.sh', $buildWorkflow);
    }

    private static function readRepoFile(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__).'/'.$path);
        self::assertIsString($contents, "{$path} must be readable.");

        return $contents;
    }
}
