# PHP test runtime

The no-database Dockerfile, Composer CA verifier and Brotli inputs are reused
byte-for-byte from SDK PHP source
[`4d37a42d`](https://github.com/durable-workflow/sdk-php/tree/4d37a42d1c95020fa2c75dcd0cc7585dd788f71c/docker/php-ci), itself adapted
from the accepted Sample App container pattern. Keep these common build inputs
aligned before starting another base build. CLI owns its minor selection,
runtime probe, restricted launcher environment and actual test results.
`cli.Dockerfile` extends that base with the pinned Node binary and procps,
which the existing release-contract and installer-shell fixtures require.

`scripts/ci/php-container-images.json` selects immutable official PHP images
for 8.2, 8.4 and 8.5. Composer is pinned to the reviewed 2.10.3 image index.
The build requires the APT helper from central source
`18783be6bef737cc778e4c7282a8797e39daa61a`; setup checks its commit and file
digest before building. The probe requires production INI, no coverage,
PHPUnit's modules and the process-control functions exercised by CLI tests.

The setup script runs with authenticated CA environment from the dependency
trust bootstrap and private runner state. It inspects the built image ID,
runs the actual Composer TLS control, then installs `php` and `composer`
launchers. Those launchers forward the selected CA files as read-only mounts
and keep unrelated host credentials outside the runtime. The caller's normal
Composer verifier still checks the actual container PHAR before dependency
requests and again before project scripts.

The repository and launcher state are mounted read-only. Separate writable
mounts contain `vendor`, PHPUnit's result-cache data, the private home,
Composer state and temporary files. The launcher refuses symlinked writable
mount roots. Application code cannot replace a host wrapper or its source
through those mounts. A disposable-source control attempts direct launcher
writes, wrapper unlink/replacement and rename, then invokes the next host PHP
entry. The container test cells depend on the accepted central policy before
executing a changed runtime source.

The Rust source-reference caller has a separate `reference.ini` profile with
unlimited PHP memory, UTC, PHAR writes disabled and CLI OPcache disabled. Its
launcher exposes only `rust/target/php-vectors.json` as writable under the Rust
build tree. Compiled binaries, source and host controls stay read-only. Parity
config directories live in private temporary data; only `DW_CONFIG_HOME` and
build identity fields needed by the source reference cross that boundary.
Run `prepare-php-reference-container.sh CONFIG_ID PRIVATE_STATE` to check an
already-inspected image, or the setup script to build the pinned runtime. The
proposed caller fixture preserves the original vector, Cargo and parity commands.

The two-build PHAR reproducibility caller reuses the reviewed PHAR profile
through a separate launcher. Its archived trees and comparison artifacts
live outside PHP's writable temporary data. The host prepares one selected
tree at a time; PHP sees that tree read-only with only vendor, build and the
generated build-info file writable. Other trees and the compared PHAR copy
are not mounted. The original build script's mutable Box cache path is
routed through the existing guard and read-only pinned Box artifact.

The prepared caller is `scripts/ci/verify-php-reproducible-build.sh`; the
ordinary local script is unchanged. Each host-prepared tree receives a
read-only Box configuration with its timestamp set from `SOURCE_DATE_EPOCH`.
This pins PHAR metadata as well as the original input-file normalization.
