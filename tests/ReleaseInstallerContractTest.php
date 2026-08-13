<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ReleaseInstallerContractTest extends TestCase
{
    public function test_default_installers_resolve_the_qualified_prerelease_channel(): void
    {
        $shell = self::readRepoFile('scripts/install.sh');
        $powershell = self::readRepoFile('scripts/install.ps1');

        self::assertStringContainsString('VERSION="${VERSION:-prerelease}"', $shell);
        self::assertStringContainsString('public-artifact-compatibility-evidence.json', $shell);
        self::assertStringContainsString('/"qualified_artifact_versions"', $shell);
        self::assertStringContainsString('/"cli"', $shell);
        self::assertStringNotContainsString('api.github.com/repos/${REPO}/releases', $shell);
        self::assertStringContainsString("else { 'prerelease' }", $powershell);
        self::assertStringContainsString('public-artifact-compatibility-evidence.json', $powershell);
        self::assertStringContainsString('$authority.qualified_artifact_versions.cli', $powershell);
        self::assertStringNotContainsString('api.github.com/repos/$repo/releases', $powershell);
    }

    public function test_shell_installer_resolves_and_installs_from_the_prerelease_channel(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('The shell installer fixture exercises the Linux artifact path.');
        }

        $architecture = match (php_uname('m')) {
            'x86_64', 'amd64' => 'x86_64',
            'aarch64', 'arm64' => 'aarch64',
            default => null,
        };
        if ($architecture === null) {
            self::markTestSkipped('The shell installer fixture requires a supported Linux architecture.');
        }

        $fixtureRoot = sys_get_temp_dir().'/dw-prerelease-installer-'.bin2hex(random_bytes(8));
        $mockBin = $fixtureRoot.'/bin';
        $installDir = $fixtureRoot.'/install';
        $asset = "dw-linux-{$architecture}";
        $assetPath = $fixtureRoot.'/'.$asset;
        $sumsPath = $fixtureRoot.'/SHA256SUMS';
        $authorityPath = $fixtureRoot.'/qualified-authority.json';
        $curlLogPath = $fixtureRoot.'/curl.log';

        self::assertTrue(mkdir($mockBin, 0o777, true));
        self::assertTrue(mkdir($installDir, 0o777, true));

        try {
            self::assertIsInt(file_put_contents(
                $assetPath,
                "#!/usr/bin/env sh\nprintf '%s\\n' 'dw 2.0.0-rc.998'\n",
            ));
            self::assertIsInt(file_put_contents(
                $sumsPath,
                hash_file('sha256', $assetPath)."  {$asset}\n",
            ));
            self::assertIsInt(file_put_contents(
                $authorityPath,
                json_encode([
                    'schema' => 'durable-workflow.docs.public-artifact-compatibility-evidence',
                    'schema_version' => 2,
                    'outcome' => 'pass',
                    'qualified_artifact_versions' => ['cli' => '2.0.0-rc.998'],
                ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            ));

            $mockCurl = <<<'SH'
#!/usr/bin/env sh
set -eu

output=""
url=""
while [ "$#" -gt 0 ]; do
    case "$1" in
        -o) output="$2"; shift 2 ;;
        -H|--retry) shift 2 ;;
        -*) shift ;;
        *) url="$1"; shift ;;
    esac
done

printf '%s\n' "$url" >> "$MOCK_CURL_LOG"

case "$url" in
    "$MOCK_QUALIFIED_AUTHORITY_URL") source="$MOCK_QUALIFIED_AUTHORITY_FILE" ;;
    "$MOCK_RELEASE_BASE_URL/download/2.0.0-rc.998/$MOCK_RELEASE_ASSET_NAME") source="$MOCK_RELEASE_ASSET_FILE" ;;
    "$MOCK_RELEASE_BASE_URL/download/2.0.0-rc.998/SHA256SUMS") source="$MOCK_RELEASE_SUMS_FILE" ;;
    "$MOCK_RELEASE_BASE_URL/download/2.0.0-rc.999/$MOCK_RELEASE_ASSET_NAME") source="$MOCK_RELEASE_ASSET_FILE" ;;
    "$MOCK_RELEASE_BASE_URL/download/2.0.0-rc.999/SHA256SUMS") source="$MOCK_RELEASE_SUMS_FILE" ;;
    *) exit 22 ;;
esac

if [ -n "$output" ]; then
    cp "$source" "$output"
else
    cat "$source"
fi
SH;
            $mockCurlPath = $mockBin.'/curl';
            self::assertIsInt(file_put_contents($mockCurlPath, $mockCurl));
            self::assertTrue(chmod($mockCurlPath, 0o755));

            $qualifiedAuthorityUrl = 'https://releases.invalid/qualified-authority.json';
            $releaseBaseUrl = 'https://releases.invalid/releases';
            $environment = [
                'PATH' => $mockBin.PATH_SEPARATOR.(getenv('PATH') ?: ''),
                'DURABLE_WORKFLOW_INSTALL_DIR' => $installDir,
                'DURABLE_WORKFLOW_QUALIFIED_AUTHORITY_URL' => $qualifiedAuthorityUrl,
                'DURABLE_WORKFLOW_RELEASE_BASE_URL' => $releaseBaseUrl,
                'MOCK_QUALIFIED_AUTHORITY_URL' => $qualifiedAuthorityUrl,
                'MOCK_QUALIFIED_AUTHORITY_FILE' => $authorityPath,
                'MOCK_RELEASE_BASE_URL' => $releaseBaseUrl,
                'MOCK_RELEASE_ASSET_NAME' => $asset,
                'MOCK_RELEASE_ASSET_FILE' => $assetPath,
                'MOCK_RELEASE_SUMS_FILE' => $sumsPath,
                'MOCK_CURL_LOG' => $curlLogPath,
            ];
            $process = new Process(
                ['sh', dirname(__DIR__).'/scripts/install.sh'],
                null,
                $environment,
            );
            $process->mustRun();

            self::assertTrue(is_executable($installDir.'/dw'));
            self::assertStringContainsString('Resolving the qualified CLI prerelease', $process->getOutput());
            self::assertStringContainsString('dw 2.0.0-rc.998', $process->getOutput());
            self::assertStringContainsString($qualifiedAuthorityUrl, (string) file_get_contents($curlLogPath));

            self::assertIsInt(file_put_contents(
                $assetPath,
                "#!/usr/bin/env sh\nprintf '%s\\n' 'dw 2.0.0-rc.999'\n",
            ));
            self::assertIsInt(file_put_contents(
                $sumsPath,
                hash_file('sha256', $assetPath)."  {$asset}\n",
            ));
            self::assertIsInt(file_put_contents($curlLogPath, ''));

            $pinnedProcess = new Process(
                ['sh', dirname(__DIR__).'/scripts/install.sh'],
                null,
                $environment + ['VERSION' => '2.0.0-rc.999'],
            );
            $pinnedProcess->mustRun();

            self::assertStringNotContainsString('Resolving the qualified CLI prerelease', $pinnedProcess->getOutput());
            self::assertStringContainsString('dw 2.0.0-rc.999', $pinnedProcess->getOutput());
            $pinnedCurlLog = (string) file_get_contents($curlLogPath);
            self::assertStringNotContainsString($qualifiedAuthorityUrl, $pinnedCurlLog);
            self::assertStringContainsString(
                "{$releaseBaseUrl}/download/2.0.0-rc.999/{$asset}",
                $pinnedCurlLog,
            );
        } finally {
            self::removeTree($fixtureRoot);
        }
    }

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

    public function test_release_phpmicro_toolchain_is_pinned_verified_and_trust_scoped(): void
    {
        $releaseWorkflow = self::readRepoFile('.github/workflows/release.yml');

        self::assertSame(2, substr_count($releaseWorkflow, "SPC_VERSION: '2.8.5'"));
        self::assertSame(2, substr_count(
            $releaseWorkflow,
            'SPC_REVISION: 4318ef8fa32a02460ec1554746674a7bc42b49fa',
        ));
        self::assertSame(1, substr_count($releaseWorkflow, "PHP_VERSION: '8.4.23'"));
        self::assertSame(1, substr_count($releaseWorkflow, "PHP_VERSION_WINDOWS: '8.4.23'"));
        self::assertStringContainsString(
            'spc_sha256: 523ba4279c54c7a377156c0dd3a36adf92ee64b01e9a7f5e9e2ec084b8e458e5',
            $releaseWorkflow,
        );
        self::assertStringContainsString(
            'spc_sha256: 675a3840dcdc4ed041fe20eaa54310ce019a9984c1c03951df9ec66df5795213',
            $releaseWorkflow,
        );
        self::assertStringContainsString(
            'spc_sha256: acf2f25d56d0cbf8e65aa82e5054fef555f7be7c5c38046c6e0819f266d83225',
            $releaseWorkflow,
        );
        self::assertStringContainsString(
            'SPC_WINDOWS_SHA256: 425b54ab857e21409c1fd9b818899ffebabd1e2817ef0a0ed5ae8a3d9f5b463b',
            $releaseWorkflow,
        );
        self::assertStringContainsString('releases/download/${SPC_VERSION}/${{ matrix.spc_asset }}', $releaseWorkflow);
        self::assertStringContainsString('releases/download/$env:SPC_VERSION/spc-windows-x64.exe', $releaseWorkflow);
        self::assertStringContainsString('actual_sha256="$(sha256sum "$archive"', $releaseWorkflow);
        self::assertStringContainsString('Get-FileHash -Algorithm SHA256 $spcPath', $releaseWorkflow);
        self::assertStringNotContainsString('/nightly/', $releaseWorkflow);
        self::assertStringNotContainsString('git clone', $releaseWorkflow);

        self::assertSame(3, substr_count($releaseWorkflow, "github.repository == 'durable-workflow/cli'"));
        self::assertSame(3, substr_count($releaseWorkflow, "github.event_name != 'pull_request'"));
        self::assertSame(3, substr_count(
            $releaseWorkflow,
            "(github.event_name == 'workflow_dispatch' && github.ref == 'refs/heads/main')",
        ));
        self::assertSame(2, substr_count(
            $releaseWorkflow,
            'key: phpmicro-${{ env.PHPMICRO_CACHE_SCHEMA }}-${{ steps.phpmicro-toolchain.outputs.toolchain_id }}',
        ));
        self::assertStringNotContainsString('restore-keys:', $releaseWorkflow);
        self::assertStringContainsString(
            'MUSL_SHA256: a9a118bbe84d8764da0ea0d28b3ab3fae8477fc7e4085d90102b8596fc7c75e4',
            $releaseWorkflow,
        );
        self::assertStringContainsString(
            'key: native-prerequisite-${{ env.NATIVE_PREREQUISITE_CACHE_SCHEMA }}-musl-'.
            '${{ env.MUSL_VERSION }}-${{ env.MUSL_SHA256 }}',
            $releaseWorkflow,
        );
        self::assertStringContainsString('native_prerequisite_identity=', $releaseWorkflow);
        self::assertStringContainsString('native_prerequisite_script_sha256=', $releaseWorkflow);

        foreach ([
            'spc_revision=',
            'php_version=',
            'extensions=',
            'workflow_sha256=',
            'runner_label=',
            'runner_os=',
            'runner_arch=',
            'runner_image=',
        ] as $cacheInput) {
            self::assertGreaterThanOrEqual(2, substr_count($releaseWorkflow, $cacheInput), $cacheInput);
        }
        self::assertSame(2, substr_count($releaseWorkflow, 'build/.tools/buildroot/bin/micro.sfx'));
        self::assertSame(2, substr_count(
            $releaseWorkflow,
            'Checkout qualified phpmicro toolchain policy',
        ));
        self::assertSame(4, substr_count(
            $releaseWorkflow,
            'ref: ${{ needs.resolve-release.outputs.control_commit }}',
        ));
        self::assertStringContainsString(
            'release-toolchain-control/.github/workflows/release.yml',
            $releaseWorkflow,
        );
        self::assertStringContainsString(
            'release-toolchain-control\.github\workflows\release.yml',
            $releaseWorkflow,
        );
        self::assertSame(4, substr_count($releaseWorkflow, 'schema=durable-workflow.phpmicro-cache/v1'));
        self::assertSame(2, substr_count($releaseWorkflow, 'cache_valid=true'));
        self::assertSame(5, substr_count(
            $releaseWorkflow,
            "if: steps.verify-phpmicro-cache.outputs.cache_valid != 'true'",
        ));
        self::assertStringContainsString('Discarding untrusted or invalid phpmicro cache', $releaseWorkflow);
        self::assertStringContainsString('rm -rf source buildroot', $releaseWorkflow);
        self::assertStringNotContainsString('rm -rf downloads source buildroot', $releaseWorkflow);
        self::assertStringContainsString(
            'Remove-Item -Recurse -Force downloads,source,buildroot',
            $releaseWorkflow,
        );
        self::assertStringContainsString('runner: ubuntu-24.04', $releaseWorkflow);
        self::assertStringContainsString('runner: ubuntu-24.04-arm', $releaseWorkflow);
        self::assertStringContainsString('runner: macos-14', $releaseWorkflow);
        self::assertStringContainsString('runs-on: windows-2022', $releaseWorkflow);
        self::assertStringNotContainsString('runs-on: windows-latest', $releaseWorkflow);
        self::assertStringContainsString(
            'sudo apt-get install --no-install-recommends -y build-essential pkg-config',
            $releaseWorkflow,
        );
        self::assertStringContainsString('brew install pkgconf', $releaseWorkflow);
        self::assertStringContainsString(
            '-requires Microsoft.VisualStudio.Component.VC.Tools.x86.x64',
            $releaseWorkflow,
        );
        self::assertStringContainsString(
            '$sevenZip = "php-sdk-binary-tools\bin\7za.exe"',
            $releaseWorkflow,
        );
        self::assertStringContainsString(
            'Test-Path source\php-src\main\php_version.h -PathType Leaf',
            $releaseWorkflow,
        );
        self::assertSame(2, substr_count(
            $releaseWorkflow,
            'native_toolchain_id=${{ steps.native-prerequisites.outputs.toolchain_id }}',
        ));
        self::assertStringContainsString('runner_label=windows-2022', $releaseWorkflow);

        self::assertSame(2, substr_count($releaseWorkflow, "PHPMICRO_UNCACHED_BASELINE_SECONDS: '480'"));
        self::assertSame(2, substr_count(
            $releaseWorkflow,
            'durable-workflow.cli.phpmicro-toolchain-timing/v2',
        ));
        self::assertSame(2, substr_count($releaseWorkflow, 'build/.tools/phpmicro-timing.json'));
        self::assertStringContainsString('previous_uncached_baseline_seconds', $releaseWorkflow);
    }

    public function test_release_phpmicro_cache_has_a_non_publishing_default_branch_warm_path(): void
    {
        $releaseWorkflow = self::readRepoFile('.github/workflows/release.yml');

        self::assertStringContainsString(
            "push:\n    branches:\n      - main\n    tags:",
            $releaseWorkflow,
        );
        self::assertStringContainsString("schedule:\n    - cron:", $releaseWorkflow);
        self::assertStringContainsString('phpmicro-cache-warm', $releaseWorkflow);
        self::assertMatchesRegularExpression(
            '/tag:\n\s+description: \'Release tag required for release.*\n\s+required: false/',
            $releaseWorkflow,
        );
        self::assertMatchesRegularExpression(
            '/release_commit:\n\s+description: \'Exact source commit.*\n\s+required: false/',
            $releaseWorkflow,
        );
        self::assertStringContainsString('DISPATCH_OPERATION: ${{ inputs.operation }}', $releaseWorkflow);
        self::assertStringContainsString('[ "$CONTROL_REF" = "refs/heads/main" ]', $releaseWorkflow);
        self::assertStringContainsString('cache_warm=true', $releaseWorkflow);
        self::assertStringContainsString('tag=""', $releaseWorkflow);
        self::assertStringContainsString('commit="$CONTROL_COMMIT"', $releaseWorkflow);
        self::assertStringContainsString('cache_warm: ${{ steps.resolve.outputs.cache_warm }}', $releaseWorkflow);

        self::assertSame(3, substr_count(
            $releaseWorkflow,
            "needs.resolve-release.outputs.cache_warm == 'true' &&\n".
            "            github.ref == 'refs/heads/main'",
        ));
        self::assertSame(3, substr_count(
            $releaseWorkflow,
            "needs.resolve-release.outputs.cache_warm != 'true' &&\n".
            "            ((github.event_name == 'push' && startsWith(github.ref, 'refs/tags/'))",
        ));
        self::assertSame(2, substr_count(
            $releaseWorkflow,
            'key: phpmicro-${{ env.PHPMICRO_CACHE_SCHEMA }}-${{ steps.phpmicro-toolchain.outputs.toolchain_id }}',
        ));
        self::assertGreaterThanOrEqual(10, substr_count(
            $releaseWorkflow,
            "needs.resolve-release.outputs.cache_warm != 'true'",
        ));

        foreach ([
            'CACHE_KEY:',
            'CACHE_SCOPE:',
            'GIT_REF:',
            'PLATFORM:',
            'RUN_PURPOSE:',
            'cache_key',
            'cache_scope',
            'git_ref',
            'platform',
            'run_purpose',
        ] as $timingField) {
            self::assertGreaterThanOrEqual(2, substr_count($releaseWorkflow, $timingField), $timingField);
        }
        self::assertSame(2, substr_count(
            $releaseWorkflow,
            'durable-workflow.cli.phpmicro-toolchain-timing/v2',
        ));

        $preflight = self::workflowJob($releaseWorkflow, 'release-preflight', 'build-phar');
        $buildPhar = self::workflowJob($releaseWorkflow, 'build-phar', 'build-binary');
        $bundle = self::workflowJob($releaseWorkflow, 'bundle-release', 'release');
        $publish = self::workflowJob($releaseWorkflow, 'release', null);
        foreach ([$preflight, $buildPhar, $bundle, $publish] as $releaseOnlyJob) {
            self::assertStringContainsString(
                "needs.resolve-release.outputs.cache_warm != 'true'",
                $releaseOnlyJob,
            );
        }
        self::assertStringContainsString('Create GitHub Release', $publish);
        self::assertStringNotContainsString('Create GitHub Release', $bundle);
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
        self::assertStringContainsString('ref: ${{ github.sha }}', $releaseWorkflow);
        self::assertStringContainsString('control_ref: ${{ steps.resolve.outputs.control_ref }}', $releaseWorkflow);
        self::assertStringContainsString('control_commit: ${{ steps.resolve.outputs.control_commit }}', $releaseWorkflow);
        self::assertStringContainsString('initiator: ${{ steps.resolve.outputs.initiator }}', $releaseWorkflow);
        self::assertSame(2, substr_count($releaseWorkflow, 'Checkout qualified release policy authority'));
        self::assertSame(2, substr_count($releaseWorkflow, 'release-control/scripts/ci/check-docs-release-audit.sh'));
        self::assertSame(2, substr_count($releaseWorkflow, 'release-control/scripts/ci/verify-release-tag-source.sh'));
        self::assertStringContainsString('release-control/scripts/verify-public-release-assets.sh', $releaseWorkflow);
        self::assertStringNotContainsString('run: scripts/ci/check-docs-release-audit.sh', $releaseWorkflow);
        self::assertStringContainsString('durable-workflow.cli.release-control-authority/v1', $releaseWorkflow);
        self::assertStringContainsString('"control": {"ref": "%s", "commit": "%s"}', $releaseWorkflow);
        self::assertSame(5, substr_count($releaseWorkflow, 'ref: ${{ needs.resolve-release.outputs.commit }}'));
        self::assertStringNotContainsString('|| github.ref }}', $releaseWorkflow);
        self::assertStringContainsString('EXPECTED_COMMIT: ${{ needs.resolve-release.outputs.commit }}', $releaseWorkflow);
        self::assertSame(2, substr_count($releaseWorkflow, 'RELEASE_COMMIT: ${{ needs.resolve-release.outputs.commit }}'));
        self::assertStringNotContainsString('return_run_details', $recoveryWorkflow);
        self::assertStringContainsString('"release_commit": commit', $recoveryWorkflow);
        self::assertStringContainsString('"ref": "main"', $recoveryWorkflow);
        self::assertStringContainsString('--required-event workflow_dispatch', $recoveryWorkflow);
        self::assertStringContainsString('--required-head-branch main', $recoveryWorkflow);
        self::assertStringContainsString('select-tag-push-run', $recoveryWorkflow);
        self::assertStringContainsString('gh run cancel "$run_id"', $recoveryWorkflow);
        self::assertStringContainsString('retain-tag-push-run', $recoveryWorkflow);
        self::assertStringContainsString('release-tag-push-quarantine-evidence.json', $recoveryWorkflow);
        self::assertStringContainsString('verify-publication:', $recoveryWorkflow);
        self::assertStringContainsString("needs: [discover, publish]", $recoveryWorkflow);
        self::assertStringContainsString('persist-credentials: false', $recoveryWorkflow);
        self::assertSame(1, substr_count($recoveryWorkflow, 'scripts/ci/verify-release-tag-source.sh'));
        self::assertStringContainsString('CLI_RELEASE_DEPLOY_KEY: ${{ secrets.CLI_RELEASE_DEPLOY_KEY }}', $recoveryWorkflow);
        self::assertStringContainsString('GH_TOKEN: ${{ github.token }}', $recoveryWorkflow);
        self::assertStringContainsString("tags:\n      - '[0-9]+.[0-9]+.[0-9]+*'", $releaseWorkflow);
        self::assertStringContainsString("workflow_dispatch:\n    inputs:", $releaseWorkflow);

        $credentialCheck = strpos($recoveryWorkflow, 'Require repository publication credential');
        $tagCreation = strpos($recoveryWorkflow, 'Create or verify the exact planned source tag');
        $tagQuarantine = strpos($recoveryWorkflow, 'Quarantine the exact tag-triggered publication run');
        $mainDispatch = strpos($recoveryWorkflow, 'Start or resume the exact repository-owned publication run');
        $verificationJob = strpos($recoveryWorkflow, '  verify-publication:');
        self::assertIsInt($credentialCheck);
        self::assertIsInt($tagCreation);
        self::assertIsInt($tagQuarantine);
        self::assertIsInt($mainDispatch);
        self::assertIsInt($verificationJob);
        self::assertLessThan($tagCreation, $credentialCheck);
        self::assertLessThan($tagQuarantine, $tagCreation);
        self::assertLessThan($mainDispatch, $tagQuarantine);
        self::assertLessThan($verificationJob, $mainDispatch);
        self::assertStringNotContainsString(
            '--component cli --plan',
            substr($recoveryWorkflow, $tagCreation, $verificationJob - $tagCreation),
        );

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

    public function test_recovery_ignores_push_run_and_rejects_tag_movement_before_publication(): void
    {
        $releaseWorkflow = self::readRepoFile('.github/workflows/release.yml');
        $plannedCommit = str_repeat('a', 40);
        $movedCommit = str_repeat('b', 40);
        $controlCommit = str_repeat('c', 40);
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
                'url' => 'https://github.com/durable-workflow/cli/actions/runs/1234',
            ],
            [
                'databaseId' => 1235,
                'displayTitle' => 'Release 1.2.3-alpha.4 for release-plan/recovery-test',
                'event' => 'workflow_dispatch',
                'headBranch' => 'main',
                'headSha' => $controlCommit,
                'status' => 'in_progress',
                'conclusion' => null,
                'url' => 'https://github.com/durable-workflow/cli/actions/runs/1235',
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
                '--required-event',
                'workflow_dispatch',
                '--required-head-branch',
                'main',
                '--required-display-title',
                'Release 1.2.3-alpha.4 for release-plan/recovery-test',
                '--runs',
                $publicationRuns,
            ], dirname(__DIR__));
            self::assertSame(0, $selection->run(), $selection->getErrorOutput());
            self::assertSame("wait\t1235\tin_progress\t\n", $selection->getOutput());

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
        self::assertStringContainsString('.\spc.exe extract php-src', $releaseWorkflow);
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

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($path);
    }

    private static function workflowJob(string $workflow, string $job, ?string $nextJob): string
    {
        $start = strpos($workflow, "\n  {$job}:\n");
        self::assertIsInt($start, "{$job} job must exist.");
        if ($nextJob === null) {
            return substr($workflow, $start);
        }

        $end = strpos($workflow, "\n  {$nextJob}:\n", $start + 1);
        self::assertIsInt($end, "{$nextJob} job must follow {$job}.");

        return substr($workflow, $start, $end - $start);
    }
}
