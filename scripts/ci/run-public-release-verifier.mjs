import verifier from './verify-installed-release.js';

try {
  await verifier.main();
} catch (error) {
  process.stderr.write(`Public CLI verification failed: ${error.message}\n`);
  process.exitCode = 1;
}
