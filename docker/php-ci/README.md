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
