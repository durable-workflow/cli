'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {spawnSync} = require('node:child_process');
const test = require('node:test');
const {packageMetadata, childEnvironment, CELL_MILLISECONDS} = require('./verify-installed-release');

test('native PHP reads synthetic PHAR metadata without executing its application stub', () => {
  const php = process.env.DW_TEST_PHP_BINARY;
  assert.ok(typeof php === 'string' && path.isAbsolute(php), 'DW_TEST_PHP_BINARY must name the qualified absolute PHP executable');
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
    const environment = childEnvironment(process.env, directory);
    const created = spawnSync(php, ['-d', 'phar.readonly=0', '-r', source, filename], {
      env: environment, encoding: 'utf8', timeout: 10000,
    });
    assert.ifError(created.error);
    assert.equal(created.status, 0, created.stderr);
    const started = Date.now();
    const execute = (command, args, options) => {
      assert.equal(command, 'php');
      const result = spawnSync(php, args, {encoding: 'utf8', ...options});
      assert.ifError(result.error);
      if (result.status !== 0) throw new Error('Synthetic PHAR metadata read failed');
      return result.stdout;
    };
    const actual = packageMetadata(filename, environment,
      {started, deadline: started + CELL_MILLISECONDS}, execute);
    assert.deepEqual(actual, {version: '2.3.7', commit: 'a'.repeat(40),
      packages: {'fixture/package': {pretty_version: '1.2.3', reference: 'fixture'}}});
    assert.equal(fs.existsSync(path.join(directory, 'stub-executed')), false);
    fs.writeFileSync(filename, 'malformed synthetic archive');
    assert.throws(() => packageMetadata(filename, environment,
      {started, deadline: started + CELL_MILLISECONDS}, execute));
  } finally { fs.rmSync(directory, {recursive: true, force: true}); }
});
