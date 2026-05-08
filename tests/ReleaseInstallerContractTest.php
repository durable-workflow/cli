<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class ReleaseInstallerContractTest extends TestCase
{
    public function test_installers_are_versioned_release_assets(): void
    {
        $releaseWorkflow = self::readRepoFile('.github/workflows/release.yml');

        self::assertStringContainsString('cp scripts/install.sh dist/install.sh', $releaseWorkflow);
        self::assertStringContainsString('cp scripts/install.ps1 dist/install.ps1', $releaseWorkflow);
        self::assertStringContainsString('cp scripts/verify-release.sh dist/verify-release.sh', $releaseWorkflow);
        self::assertStringContainsString('install.sh', $releaseWorkflow);
        self::assertStringContainsString('install.ps1', $releaseWorkflow);
        self::assertStringContainsString('verify-release.sh', $releaseWorkflow);
        self::assertStringContainsString('subject-path: dist/*', $releaseWorkflow);
    }

    public function test_build_validates_installer_scripts(): void
    {
        $buildWorkflow = self::readRepoFile('.github/workflows/build.yml');

        self::assertStringContainsString('sh -n scripts/install.sh', $buildWorkflow);
        self::assertStringContainsString('sh -n scripts/generate-homebrew-formula.sh', $buildWorkflow);
        self::assertStringContainsString('sh -n scripts/verify-release.sh', $buildWorkflow);
        self::assertStringContainsString('scripts/install.ps1', $buildWorkflow);
    }

    public function test_release_includes_checksum_and_attestation_verifier(): void
    {
        $verifier = self::readRepoFile('scripts/verify-release.sh');
        $readme = self::readRepoFile('README.md');

        self::assertStringContainsString('SHA256SUMS', $verifier);
        self::assertStringContainsString('sha256sum -c SHA256SUMS --ignore-missing', $verifier);
        self::assertStringContainsString('gh attestation verify', $verifier);
        self::assertStringContainsString('DURABLE_WORKFLOW_VERIFY_ATTESTATIONS', $verifier);
        self::assertStringContainsString('Tagged releases include `verify-release.sh`', $readme);
        self::assertStringContainsString('verify-release.sh --attest', $readme);
    }

    public function test_release_publishes_generated_homebrew_formula(): void
    {
        $releaseWorkflow = self::readRepoFile('.github/workflows/release.yml');
        $formulaGenerator = self::readRepoFile('scripts/generate-homebrew-formula.sh');
        $readme = self::readRepoFile('README.md');

        self::assertStringContainsString('Generate Homebrew formula', $releaseWorkflow);
        self::assertStringContainsString('scripts/generate-homebrew-formula.sh dist "$GITHUB_REF_NAME"', $releaseWorkflow);
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
        self::assertStringContainsString('gh attestation verify "$tmp" --repo "$REPO"', $shellInstaller);
        self::assertStringContainsString('gh attestation verify "$sums" --repo "$REPO"', $shellInstaller);
        self::assertStringContainsString('mv "$tmp" "$INSTALL_DIR/$BIN_NAME"', $shellInstaller);

        self::assertStringContainsString('SHA256SUMS', $powershellInstaller);
        self::assertStringContainsString('Checksum verification failed', $powershellInstaller);
        self::assertStringContainsString('DURABLE_WORKFLOW_INSTALL_VERIFY_ATTESTATIONS', $powershellInstaller);
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
