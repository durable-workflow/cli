import smoke from './run-upgrade-smoke.js';

try {
  await smoke.main();
} catch (error) {
  process.stderr.write(`Required upgrade build-smoke control failed: ${error.message}\n`);
  process.exitCode = 1;
}
