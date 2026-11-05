'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {spawnSync} = require('node:child_process');
const test = require('node:test');
const {packageMetadata, assertPackageIdentity, childEnvironment, CELL_MILLISECONDS} = require('./verify-installed-release');

function qualifiedPhp() {
  const php = process.env.DW_TEST_PHP_BINARY;
  assert.ok(typeof php === 'string' && path.isAbsolute(php), 'DW_TEST_PHP_BINARY must name the qualified absolute PHP executable');
  return {php, execute(command, args, options) {
    assert.equal(command, 'php');
    const result = spawnSync(php, args, {encoding: 'utf8', ...options});
    assert.ifError(result.error);
    if (result.status !== 0) throw new Error('PHAR metadata source control failed');
    return result.stdout;
  }};
}

function privateEnvironment(directory) {
  for (const name of ['home', 'tmp']) fs.mkdirSync(path.join(directory, name), {mode: 0o700});
  return childEnvironment(process.env, directory);
}

test('native PHP reads synthetic PHAR metadata without executing its application stub', () => {
  const {php, execute} = qualifiedPhp();
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'dw-phar-metadata-fixture-'));
  try {
    const filename = path.join(directory, 'fixture.phar');
    const source = String.raw`
$phar = new Phar($argv[1]);
$phar->startBuffering();
$phar->setStub('<?php file_put_contents(__DIR__."/stub-executed", "bad"); __HALT_COMPILER();');
$phar['vendor/composer/installed.php'] = '<?php return ["versions" => ["fixture/package" => ["pretty_version" => "1.2.3", "reference" => "fixture"]]];';
$phar['src/GeneratedBuildInfo.php'] = '<?php namespace DurableWorkflow\\Cli; final class GeneratedBuildInfo { public const VERSION = "2.3.7"; public const COMMIT = "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"; }';
$phar->setSignatureAlgorithm(Phar::SHA256);
$phar->stopBuffering();
`;
    const environment = privateEnvironment(directory);
    const created = spawnSync(php, ['-d', 'phar.readonly=0', '-r', source, filename], {
      env: environment, encoding: 'utf8', timeout: 10000,
    });
    assert.ifError(created.error);
    assert.equal(created.status, 0, created.stderr);
    const started = Date.now();
    const actual = packageMetadata(filename, environment,
      {started, deadline: started + CELL_MILLISECONDS}, execute);
    assert.deepEqual(actual, {version: '2.3.7', commit: 'a'.repeat(40), application: null,
      packages: {'fixture/package': {pretty_version: '1.2.3', reference: 'fixture'}}});
    assert.equal(fs.existsSync(path.join(directory, 'stub-executed')), false);
    fs.writeFileSync(filename, 'malformed synthetic archive');
    assert.throws(() => packageMetadata(filename, environment,
      {started, deadline: started + CELL_MILLISECONDS}, execute));
  } finally { fs.rmSync(directory, {recursive: true, force: true}); }
});

if (process.env.DW_TEST_PRODUCTION_PHAR) {
  test('actual source-built PHAR has exactly the locked production dependencies and source build identity', () => {
    const {execute} = qualifiedPhp();
    const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'dw-production-phar-control-'));
    try {
      const environment = privateEnvironment(directory);
      const started = Date.now();
      const state = {started, deadline: started + CELL_MILLISECONDS};
      const code = String.raw`require $argv[1]; echo json_encode([
        'tag' => \DurableWorkflow\Cli\GeneratedBuildInfo::VERSION,
        'commit' => \DurableWorkflow\Cli\GeneratedBuildInfo::COMMIT], JSON_THROW_ON_ERROR);`;
      const expected = JSON.parse(execute('php', ['-r', code, path.join(__dirname, '../../src/GeneratedBuildInfo.php')],
        {env: environment, timeout: 10000}));
      const actual = packageMetadata(fs.realpathSync(process.env.DW_TEST_PRODUCTION_PHAR), environment, state, execute);
      const lock = JSON.parse(fs.readFileSync(path.join(__dirname, '../../composer.lock'), 'utf8'));
      assertPackageIdentity(actual, lock, expected);
    } finally { fs.rmSync(directory, {recursive: true, force: true}); }
  });
}
