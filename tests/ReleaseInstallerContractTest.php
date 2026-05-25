<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class ReleaseInstallerContractTest extends TestCase
{
    public function test_installers_are_versioned_release_assets(): void
    {
        $releaseWorkflow = self::readRepoFile('.github/workflows/release.yml');

        self::assertStringContainsString('Resolve release tag', $releaseWorkflow);
        self::assertStringContainsString('DISPATCH_TAG: ${{ inputs.tag }}', $releaseWorkflow);
        self::assertStringContainsString('0.0.1-test or v0.0.1-test', $releaseWorkflow);
        self::assertStringContainsString('raw_tag="$tag"', $releaseWorkflow);
        self::assertStringContainsString('tag="${tag#v}"', $releaseWorkflow);
        self::assertStringContainsString('ref: ${{ needs.resolve-release.outputs.tag }}', $releaseWorkflow);
        self::assertStringContainsString('DW_CLI_VERSION: ${{ needs.resolve-release.outputs.tag }}', $releaseWorkflow);
        self::assertStringContainsString('DW_CLI_COMMIT="$(git rev-parse HEAD)"', $releaseWorkflow);
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
        self::assertStringContainsString("needs.build-binary-windows.result == 'success'", $releaseWorkflow);
        self::assertStringContainsString('dw-windows-x86_64.exe', $releaseWorkflow);
        self::assertStringContainsString('.\\build\\dw-windows-x86_64.exe runtime:check', $releaseWorkflow);
        self::assertStringContainsString('release-public-download-evidence.json', $releaseWorkflow);
        self::assertStringContainsString('"artifact_versions": {"cli": "%s"}', $releaseWorkflow);
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
    }

    public function test_build_validates_installer_scripts(): void
    {
        $buildWorkflow = self::readRepoFile('.github/workflows/build.yml');

        self::assertStringContainsString('sh -n scripts/install.sh', $buildWorkflow);
        self::assertStringContainsString('sh -n scripts/generate-homebrew-formula.sh', $buildWorkflow);
        self::assertStringContainsString('sh -n scripts/verify-release.sh', $buildWorkflow);
        self::assertStringContainsString('bash -n scripts/verify-public-release-assets.sh', $buildWorkflow);
        self::assertStringContainsString('scripts/install.ps1', $buildWorkflow);
    }

    public function test_release_includes_checksum_and_attestation_verifier(): void
    {
        $verifier = self::readRepoFile('scripts/verify-release.sh');
        $publicAssetVerifier = self::readRepoFile('scripts/verify-public-release-assets.sh');
        $readme = self::readRepoFile('README.md');

        self::assertStringContainsString('SHA256SUMS', $verifier);
        self::assertStringContainsString('sha256sum -c SHA256SUMS --ignore-missing', $verifier);
        self::assertStringContainsString('gh attestation verify', $verifier);
        self::assertStringContainsString('DURABLE_WORKFLOW_VERIFY_ATTESTATIONS', $verifier);
        self::assertStringContainsString('raw_tag="${1:-}"', $publicAssetVerifier);
        self::assertStringContainsString('tag="${raw_tag#v}"', $publicAssetVerifier);
        self::assertStringContainsString('releases/download/${tag}/${artifact}', $publicAssetVerifier);
        self::assertStringContainsString('curl -fsSLI --retry 3 --retry-all-errors', $publicAssetVerifier);
        self::assertStringContainsString('dw-windows-x86_64.exe', $publicAssetVerifier);
        self::assertStringContainsString('Tagged releases include `verify-release.sh`', $readme);
        self::assertStringContainsString('verify-release.sh --attest', $readme);
    }

    public function test_release_publishes_generated_homebrew_formula(): void
    {
        $releaseWorkflow = self::readRepoFile('.github/workflows/release.yml');
        $formulaGenerator = self::readRepoFile('scripts/generate-homebrew-formula.sh');
        $readme = self::readRepoFile('README.md');

        self::assertStringContainsString('Generate Homebrew formula', $releaseWorkflow);
        self::assertStringContainsString('scripts/generate-homebrew-formula.sh dist "${{ needs.resolve-release.outputs.tag }}"', $releaseWorkflow);
        self::assertStringContainsString('tag="${tag#v}"', $formulaGenerator);
        self::assertStringContainsString('dw.rb', $formulaGenerator);
        self::assertStringContainsString('dw-macos-aarch64', $formulaGenerator);
        self::assertStringContainsString('class Dw < Formula', $formulaGenerator);
        self::assertStringContainsString('Tagged releases also include `dw.rb`', $readme);
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

    public function test_readme_names_the_installer_provenance_boundary(): void
    {
        $readme = self::readRepoFile('README.md');

        self::assertStringContainsString('Installer', $readme);
        self::assertStringContainsString('scripts live in this repository under `scripts/`', $readme);
        self::assertStringContainsString('are published with each', $readme);
        self::assertStringContainsString('tagged release', $readme);
        self::assertStringContainsString('Release assets, including the installer scripts', $readme);
        self::assertStringContainsString('Native binaries and PHARs are not currently code-signed or notarized.', $readme);
        self::assertStringContainsString('Telemetry is', $readme);
        self::assertStringContainsString('docs/distribution.md', $readme);
        self::assertStringContainsString('reproducible build', $readme);
        self::assertStringContainsString('verify-reproducible-build.sh', $readme);
        self::assertStringContainsString('VERSION=0.1.63', $readme);
        self::assertStringNotContainsString('durable-workflow.com/composer', $readme);
    }

    public function test_distribution_doc_records_phase3_decisions(): void
    {
        $doc = self::readRepoFile('docs/distribution.md');

        self::assertStringContainsString('# Distribution Policy', $doc);

        // Code signing / notarization is out of scope, with an explicit
        // rationale. Phase 3 requires either implementation or a documented
        // out-of-scope decision; this test enforces the documented form.
        self::assertStringContainsString('Code signing and notarization', $doc);
        self::assertStringContainsString('explicitly out of scope', $doc);
        self::assertStringContainsString('GitHub artifact attestations', $doc);

        // Telemetry is permanently out of scope, with an enumerated
        // behavior contract operators can audit against.
        self::assertStringContainsString('## Telemetry', $doc);
        self::assertStringContainsString('does not collect telemetry', $doc);
        self::assertStringContainsString('phone home', strtolower($doc));

        // Reproducible-build contract is in scope and verifiable.
        self::assertStringContainsString('Reproducible release builds', $doc);
        self::assertStringContainsString('SOURCE_DATE_EPOCH', $doc);
        self::assertStringContainsString('verify-reproducible-build.sh', $doc);
        self::assertStringContainsString('bit-identical', $doc);

        // Homebrew install path is documented end-to-end so the
        // generated dw.rb formula has a published install runbook.
        self::assertStringContainsString('Homebrew install path', $doc);
        self::assertStringContainsString('brew install --formula ./dw.rb', $doc);

        // Auto-update is documented as the explicit dw upgrade path.
        self::assertStringContainsString('## Auto-update', $doc);
        self::assertStringContainsString('dw upgrade', $doc);
        self::assertStringContainsString('VERSION=0.1.63', $doc);
        self::assertStringNotContainsString('durable-workflow.com/composer', $doc);
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

        self::assertStringContainsString('SOURCE_DATE_EPOCH', $verifier);
        self::assertStringContainsString('scripts/build.sh phar', $verifier);
        self::assertStringContainsString('PHAR builds are not bit-identical', $verifier);

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
