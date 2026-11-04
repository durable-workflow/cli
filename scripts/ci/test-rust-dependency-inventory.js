'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');

const {SCHEMA, inventory, lockChecksums} = require('./rust-dependency-inventory.js');

const REGISTRY = 'registry+https://github.com/rust-lang/crates.io-index';

function metadata() {
  const pkg = (name, version, source = REGISTRY) => ({
    id: `${name}@${version}`, name, version, source, license: 'MIT',
  });
  const edge = (pkg, kind = null) => ({pkg, dep_kinds: [{kind, target: null}]});
  return {
    packages: [pkg('dw', '2.5.0', null), pkg('serde', '1.0.0'), pkg('itoa', '1.0.0'), pkg('cc', '1.0.0'), pkg('tempfile', '3.0.0')],
    resolve: {
      root: 'dw@2.5.0',
      nodes: [
        {id: 'dw@2.5.0', deps: [edge('serde@1.0.0'), edge('cc@1.0.0', 'build'), edge('tempfile@3.0.0', 'dev')]},
        {id: 'serde@1.0.0', deps: [edge('itoa@1.0.0')]},
        {id: 'itoa@1.0.0', deps: []},
        {id: 'cc@1.0.0', deps: []},
        {id: 'tempfile@3.0.0', deps: []},
      ],
    },
  };
}

const LOCK = ['serde', 'itoa', 'cc', 'tempfile'].map((name) => [
  '[[package]]', `name = "${name}"`, `version = "${name === 'tempfile' ? '3.0.0' : '1.0.0'}"`,
  `source = "${REGISTRY}"`, `checksum = "${name.padEnd(64, '0')}"`, '',
].join('\n')).join('\n');

const OPTIONS = {asset: 'dw-linux-x86_64', target: 'x86_64-unknown-linux-musl', version: '2.5.0', rustc: 'rustc 1.86.0'};

test('lists only packages linked through normal dependency edges', () => {
  const result = inventory(OPTIONS, metadata(), LOCK);
  assert.equal(result.schema, SCHEMA);
  assert.deepEqual(result.packages.map((pkg) => pkg.name), ['itoa', 'serde']);
  assert.equal(result.packages[1].checksum, 'serde'.padEnd(64, '0'));
  assert.equal(result.asset, 'dw-linux-x86_64');
});

test('refuses a registry package without a locked checksum', () => {
  const lock = LOCK.replace(/checksum = "itoa0+"\n/, '');
  assert.throws(() => inventory(OPTIONS, metadata(), lock), /no checksum for registry package itoa/);
});

test('reads Cargo.lock checksums by name, version and source', () => {
  const checksums = lockChecksums(LOCK);
  assert.equal(checksums.get(`serde 1.0.0 ${REGISTRY}`), 'serde'.padEnd(64, '0'));
});
