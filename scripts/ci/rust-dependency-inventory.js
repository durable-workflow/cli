'use strict';

// Writes dw-<platform>.dependencies.json for a Rust release binary: every
// package linked into the `dw` executable for one target, with its locked
// version, source and Cargo.lock checksum.
//
// Inputs are data files produced earlier in the build job:
//   --metadata  output of `cargo metadata --format-version 1 --locked
//               --filter-platform <target>`
//   --lock      the Cargo.lock the binary was built from

const fs = require('node:fs');

const SCHEMA = 'durable-workflow.cli.rust-dependencies/v1';

function parseArguments(argv) {
  const options = {};
  for (let index = 0; index < argv.length; index += 2) {
    const name = argv[index];
    const value = argv[index + 1];
    if (!name.startsWith('--') || value === undefined) {
      throw new Error(`expected --name value pairs, got ${name}`);
    }
    options[name.slice(2)] = value;
  }
  for (const required of ['metadata', 'lock', 'asset', 'target', 'version', 'rustc', 'output']) {
    if (typeof options[required] !== 'string' || options[required] === '') {
      throw new Error(`--${required} is required`);
    }
  }
  return options;
}

// Cargo.lock [[package]] entries, keyed by "name version source".
function lockChecksums(text) {
  const checksums = new Map();
  for (const block of text.split(/^\[\[package\]\]$/m).slice(1)) {
    const field = (key) => {
      const match = new RegExp(`^${key} = "([^"]*)"$`, 'm').exec(block);
      return match ? match[1] : null;
    };
    checksums.set(`${field('name')} ${field('version')} ${field('source') ?? ''}`, field('checksum'));
  }
  return checksums;
}

// Packages reachable from the root through normal (non-dev, non-build)
// dependency edges: the code linked into the executable.
function linkedPackages(metadata) {
  const root = metadata.resolve?.root;
  if (typeof root !== 'string') {
    throw new TypeError('cargo metadata has no resolved root package');
  }
  const nodes = new Map(metadata.resolve.nodes.map((node) => [node.id, node]));
  const seen = new Set();
  const pending = [root];
  while (pending.length > 0) {
    const id = pending.pop();
    if (seen.has(id)) {
      continue;
    }
    seen.add(id);
    const node = nodes.get(id);
    if (!node) {
      throw new Error(`cargo metadata does not resolve ${id}`);
    }
    for (const dependency of node.deps) {
      if (dependency.dep_kinds.some((kind) => kind.kind === null)) {
        pending.push(dependency.pkg);
      }
    }
  }
  seen.delete(root);
  return metadata.packages.filter((pkg) => seen.has(pkg.id));
}

function inventory(options, metadata, lockText) {
  const checksums = lockChecksums(lockText);
  const packages = linkedPackages(metadata).map((pkg) => {
    const source = pkg.source ?? null;
    const checksum = checksums.get(`${pkg.name} ${pkg.version} ${source ?? ''}`) ?? null;
    if (source !== null && source.startsWith('registry+') && checksum === null) {
      throw new Error(`Cargo.lock has no checksum for registry package ${pkg.name} ${pkg.version}`);
    }
    return {name: pkg.name, version: pkg.version, source, checksum, license: pkg.license ?? null};
  });
  packages.sort((left, right) => left.name.localeCompare(right.name) || left.version.localeCompare(right.version));

  return {
    schema: SCHEMA,
    asset: options.asset,
    target: options.target,
    cli_version: options.version,
    rustc: options.rustc,
    packages,
  };
}

module.exports = {SCHEMA, inventory, linkedPackages, lockChecksums};

if (require.main === module) {
  const options = parseArguments(process.argv.slice(2));
  const metadata = JSON.parse(fs.readFileSync(options.metadata, 'utf8'));
  const lockText = fs.readFileSync(options.lock, 'utf8');
  fs.writeFileSync(options.output, `${JSON.stringify(inventory(options, metadata, lockText), null, 2)}\n`);
}
