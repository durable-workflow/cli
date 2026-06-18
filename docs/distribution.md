# Distribution Policy

This document describes how `dw` is distributed, how operators verify the
artifacts they install, and which optional distribution-hardening surfaces
are deliberately out of scope for the 0.1.x line.

It is the canonical reference for security-conscious users who need to
answer "is this binary the one the project built?" and "what does this CLI
do behind my back?". The short answers are: yes, every published asset
carries a checksum and a GitHub artifact attestation; and nothing — the
CLI talks to the configured server and nothing else.

## Supported install paths

| Path | Audience | Verification baseline |
|------|----------|-----------------------|
| One-line installer (`install.sh` / `install.ps1`) | Most users | SHA256 against the release `SHA256SUMS` manifest, optional GitHub artifact attestation when `gh` is installed and `DURABLE_WORKFLOW_INSTALL_VERIFY_ATTESTATIONS=1` is set. |
| Exact-version installer (`VERSION=<tag>`) | CI, conformance, and reproducible automation | Same checksum and optional attestation path as the one-line installer, pinned to a named release tag. |
| Direct download from the GitHub release | Operators bringing their own automation | `verify-release.sh` (bundled with each tagged release) plus `gh attestation verify` for end-to-end provenance. |
| Homebrew formula (`dw.rb`) | macOS arm64 users on Homebrew | The release-bundled formula pins the exact release URL and SHA256; `brew install` verifies the checksum before linking. |
| PHAR (`dw.phar`) plus a system PHP | PHP-aware operators | Same `SHA256SUMS` plus attestation surface as the native binaries. |

The one-line installer is the recommended path on every supported platform.
Both `install.sh` and `install.ps1` download the matching `SHA256SUMS` manifest,
verify the binary's checksum before writing it into the install directory,
and refuse to proceed when the checksum does not match.

For exact-version automation, set `VERSION` to the release tag:

```bash
curl -fsSL https://durable-workflow.com/install.sh | VERSION=0.1.80 sh
```

Release `0.1.80` is available from the GitHub release page at
<https://github.com/durable-workflow/cli/releases/tag/0.1.80>. Replace the
tag when pinning a newer release.

## Provenance boundary

The provenance boundary for the 0.1.x line is:

1. **The git tag** — every release is built from a tag pushed to
   `durable-workflow/cli`. The release workflow runs only on
   `refs/tags/<MAJOR>.<MINOR>.<PATCH>*`.
2. **`SHA256SUMS`** — generated inside the release workflow over every
   supported release asset for the tag (PHAR, Linux x86_64, Linux
   aarch64, macOS aarch64, Windows x86_64, the installer scripts, the
   `verify-release.sh` helper, and the generated Homebrew formula).
3. **GitHub artifact attestations** — the release workflow signs every
   asset (including `SHA256SUMS`) with the GitHub Actions OIDC identity
   via `actions/attest-build-provenance`. Anyone can replay that signature
   with `gh attestation verify <asset> --repo durable-workflow/cli`.

Together these three layers answer the question "was this artifact
produced by the durable-workflow/cli release workflow from the named tag?"
without trusting any one infrastructure component on its own.

### Verifying a downloaded release directory

```bash
# Download every asset (or just the ones you care about) into a directory.
gh release download <tag> --repo durable-workflow/cli --dir release/

# Verify checksums for every local file listed in SHA256SUMS.
sh release/verify-release.sh release/

# Verify GitHub artifact attestations for the same files.
sh release/verify-release.sh --attest release/
```

`verify-release.sh` is itself a release asset, so the verification helper
is pinned to the same tag as the binaries it checks.

## Code signing and notarization

**Decision: explicitly out of scope for the 0.1.x line. GitHub artifact
attestations are the primary provenance mechanism.**

### Why

`dw` is distributed as a PHAR plus an embedded-PHAR static binary built
with `static-php-cli`'s `phpmicro` SAPI. The native binaries are not the
output of a platform-native toolchain (Xcode, MSBuild) and the maintainers
do not currently hold the signing identities required to produce binaries
that pass Apple Notary Service review or carry an Authenticode signature
trusted by SmartScreen. Acquiring those identities, holding the signing
keys correctly, and integrating them into a hosted release workflow is a
distinct piece of operational work that we have intentionally not taken
on for the 0.1.x line — it would not strengthen the cryptographic
provenance of the binary, only its handling by the OS-level publisher
trust UX.

The `actions/attest-build-provenance` artifact attestation is a stronger
provenance signal than a typical OS-level code signature: it ties every
artifact to a specific commit, workflow run, and signed builder identity
issued by Sigstore's public transparency log via the GitHub Actions OIDC
provider. Operators who need machine-verifiable provenance can replay
that signature with `gh attestation verify` without trusting any
maintainer-held key material.

### What that means for users

- macOS users may see a Gatekeeper prompt on first launch. The standard
  `xattr -d com.apple.quarantine /usr/local/bin/dw` clears it. The
  one-line installer downloads via `curl`, which does not stamp the
  quarantine attribute, so installer users typically do not hit this.
- Windows users may see a SmartScreen prompt on first launch. The
  `dw.exe` binary is unsigned. Choose "More info" → "Run anyway" if you
  have already verified the SHA256 and (optionally) the artifact
  attestation.
- Operators who require a signed-and-notarized macOS or Windows binary
  today should stage their own re-signing pipeline against the published
  release artifacts using their organization's own developer ID. Any
  re-signing pipeline must verify the upstream `SHA256SUMS` and
  attestation before signing.

### What would change this

This decision is reviewable when one of the following becomes true:

- A Homebrew tap is published as the canonical macOS install path, in
  which case Apple notarization stops being load-bearing for new users.
- A maintainer-held Apple Developer ID and Authenticode signing identity
  are donated to the project alongside the operational commitment to
  protect those keys.
- A platform-native rebuild is added (for example, a Go or Rust port of
  the CLI front-end) that makes signing the build output trivial.

## Telemetry

**Decision: permanently out of scope. The CLI does not collect telemetry
and will not gain a telemetry surface in the 0.1.x line.**

### Behavior contract

`dw` makes no network requests except those that are a direct consequence
of an explicit command:

- HTTP requests to the `--server` URL (`DURABLE_WORKFLOW_SERVER_URL`,
  the resolved profile, or built-in defaults) for user-issued commands.
- HTTPS requests to GitHub's release API on `dw upgrade`, only when the
  user runs that command.
- HTTP/HTTPS requests issued by `dw bridge:webhook` and the external
  executor surfaces, only when the user invokes those commands and only
  to the targets the user supplies.

There is no:

- Background usage beacon.
- "Phone home" version check on startup or shutdown.
- Crash-report uploader, error reporter, or any analytics SDK.
- Update-availability poll outside of `dw upgrade`.

### Why

A control-plane CLI runs against environments where any unsolicited
outbound traffic is a compliance failure. Operators who use `dw` against
production Durable Workflow servers must be able to assert "this CLI
talks to my control plane and nothing else" without auditing a feature
flag or trusting an opt-out toggle. The simplest mechanism for that
assertion is to never ship a telemetry surface at all.

### What would change this

This decision is fixed for the 0.1.x line. Any future telemetry surface
would be opt-in by default, would be documented here before it shipped,
and would be subject to the same provenance and verification expectations
as the rest of the binary.

## Auto-update

`dw upgrade` performs an explicit, user-invoked self-update. It downloads
the latest release asset for the current platform, verifies its SHA256
against the release manifest, and atomically replaces the running
binary. Behavior:

- Defaults: `dw upgrade` upgrades to the newest release tag, refuses to
  proceed if checksum verification fails, and refuses to overwrite
  Composer-managed PHAR installs and Homebrew-managed binaries because
  the package manager owns those install paths. Composer package metadata
  is not a supported public CLI distribution channel for the 0.1.x line;
  use the exact-version installer or direct release assets for public,
  source-free automation.
- Flags: `--version=<tag>` for a specific release, `--dry-run` to print
  the action without performing it, `--force` to override stale-binary
  guards, and `--output=json` for scripted use.

There is no background update poll. The CLI never upgrades itself
without an explicit `dw upgrade` invocation.

## Reproducible release builds

**Decision: in scope. The build is bit-reproducible from the same source
tree, and a verifier ships in the repo so anyone can confirm it.**

### Contract

> Given the same git tag, the same toolchain versions pinned in
> `.github/workflows/release.yml`, and the same `SOURCE_DATE_EPOCH`
> recorded in the release notes, the published `dw.phar` is bit-identical
> to a locally rebuilt `dw.phar` from the same source.

The native binaries (`dw-linux-*`, `dw-macos-aarch64`,
`dw-windows-x86_64.exe`) embed the PHAR into a `static-php-cli`-built
`phpmicro` SAPI. The PHAR layer is reproducible by the contract above;
the SAPI layer reproduces given the same `static-php-cli` snapshot and
host toolchain, but pinning every host compiler to a deterministic point
is platform-specific work and is not required for the PHAR-level proof.

### How it works

- `scripts/generate-build-info.php` derives `BUILD_DATE` from
  `SOURCE_DATE_EPOCH` when set, and from `DW_CLI_BUILD_DATE` first when
  the caller wants an explicit override.
- `scripts/build.sh` defaults `SOURCE_DATE_EPOCH` to the timestamp of the
  `HEAD` commit when the caller does not export one. It also normalizes
  the mtime on every input that ends up inside the PHAR so the archive
  entry headers do not vary across builds.
- Box reads `SOURCE_DATE_EPOCH` directly when stamping the gzip layer,
  so both the entry table and the compression frame are deterministic.

### How to verify locally

```bash
git checkout <tag>
make phar                              # one-shot baseline build
scripts/verify-reproducible-build.sh   # builds twice, asserts SHA256 match
```

`scripts/verify-reproducible-build.sh` runs `scripts/build.sh phar`
twice from a clean state and fails if the resulting `dw.phar` is not
byte-identical between runs. CI runs the same check on every push so any
toolchain regression that breaks reproducibility is caught before it
reaches a tag.

### Cross-checking against a published release

```bash
# 1. Fetch the published artifact and its checksum manifest.
gh release download <tag> --repo durable-workflow/cli --dir release/ --pattern 'dw.phar' --pattern 'SHA256SUMS'

# 2. Build locally from the matching tag with the recorded epoch.
git checkout <tag>
SOURCE_DATE_EPOCH=$(git log -1 --pretty=%ct) make phar

# 3. Compare bytes.
sha256sum release/dw.phar build/dw.phar
diff <(sort release/SHA256SUMS) <(cd build && sha256sum dw.phar | sort)
```

If the local sha256 does not match the published one, that is a
reproducibility regression — please open a release-blocking issue.

## Homebrew install path

The release workflow generates `dw.rb`, a Homebrew formula that pins the
exact release URL and SHA256 of `dw-macos-aarch64`, and publishes it as
a release asset alongside the binary. Operators who consume the CLI via
Homebrew today have two options until a public tap is registered:

- **Per-release install from the bundled formula.**

  ```bash
  gh release download <tag> --repo durable-workflow/cli --pattern dw.rb --output dw.rb
  brew install --formula ./dw.rb
  ```

  The formula has the release URL and SHA256 baked in, so `brew install`
  validates the binary's checksum against the locked-in value before
  linking it into the keg.

- **Self-hosted tap.** Vendor the generated `dw.rb` into your own tap
  repository (`brew tap-new <org>/durable-workflow`,
  `cp dw.rb Formula/dw.rb`, push), and your users can then run
  `brew install <org>/durable-workflow/dw`. The formula's pinned URL
  and SHA256 keep the install path verifiable inside your tap as well.

A canonical `durable-workflow/tap` is on the roadmap. Until it lands,
the per-release install above is the supported path and should be
documented in any internal runbook that automates `dw` installs on macOS.

## Operator runbook checklist

Before promoting `dw` into a production runbook:

- Pin to a specific release tag rather than `latest`.
- Configure your installer call with
  `DURABLE_WORKFLOW_INSTALL_VERIFY_ATTESTATIONS=1` and ensure `gh` is
  available so the installer enforces attestation as well as checksum.
- For air-gapped operators: download the release directory ahead of
  time, run `verify-release.sh --attest` while you still have network
  access to GitHub's attestation API, then mirror the verified
  directory into your air-gapped environment.
- Track the published `SOURCE_DATE_EPOCH` per release if you intend to
  rebuild from source for compliance attestation.

## Change history

- 0.1.x — Initial distribution policy. Signing/notarization out of scope,
  telemetry permanently out of scope, reproducible-build contract
  established with `verify-reproducible-build.sh`.
