'use strict';

const SEMVER_PATTERN = /^(?<major>0|[1-9][0-9]*)\.(?<minor>0|[1-9][0-9]*)\.(?<patch>0|[1-9][0-9]*)(?:-(?<prerelease>(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*))*))?(?:\+(?<build>[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?$/;

function parseReleaseVersion(version) {
  if (typeof version !== 'string') {
    return null;
  }

  const match = SEMVER_PATTERN.exec(version);
  if (!match) {
    return null;
  }

  return {
    core: [match.groups.major, match.groups.minor, match.groups.patch],
    prerelease: match.groups.prerelease ? match.groups.prerelease.split('.') : null,
    build: match.groups.build ? match.groups.build.split('.') : null,
  };
}

function normalizeReleaseVersion(version) {
  if (typeof version !== 'string') {
    return null;
  }

  const normalized = version.startsWith('v') ? version.slice(1) : version;

  return parseReleaseVersion(normalized) ? normalized : null;
}

function compareNumericIdentifiers(left, right) {
  if (left.length !== right.length) {
    return left.length < right.length ? -1 : 1;
  }

  if (left === right) {
    return 0;
  }

  return left < right ? -1 : 1;
}

function compareReleaseVersions(left, right) {
  const leftVersion = parseReleaseVersion(left);
  const rightVersion = parseReleaseVersion(right);

  if (!leftVersion || !rightVersion) {
    return null;
  }

  for (let index = 0; index < leftVersion.core.length; index += 1) {
    const difference = compareNumericIdentifiers(
      leftVersion.core[index],
      rightVersion.core[index],
    );
    if (difference !== 0) {
      return difference;
    }
  }

  if (leftVersion.prerelease === null || rightVersion.prerelease === null) {
    if (leftVersion.prerelease === rightVersion.prerelease) {
      return 0;
    }

    return leftVersion.prerelease === null ? 1 : -1;
  }

  const width = Math.max(leftVersion.prerelease.length, rightVersion.prerelease.length);
  for (let index = 0; index < width; index += 1) {
    const leftPart = leftVersion.prerelease[index];
    const rightPart = rightVersion.prerelease[index];

    if (leftPart === undefined || rightPart === undefined) {
      return leftPart === undefined ? -1 : 1;
    }

    const leftNumeric = /^[0-9]+$/.test(leftPart);
    const rightNumeric = /^[0-9]+$/.test(rightPart);
    if (leftNumeric && rightNumeric) {
      const difference = compareNumericIdentifiers(leftPart, rightPart);
      if (difference !== 0) {
        return difference;
      }
      continue;
    }

    if (leftNumeric !== rightNumeric) {
      return leftNumeric ? -1 : 1;
    }

    if (leftPart !== rightPart) {
      return leftPart < rightPart ? -1 : 1;
    }
  }

  return 0;
}

module.exports = {
  compareReleaseVersions,
  normalizeReleaseVersion,
  parseReleaseVersion,
};

if (require.main === module) {
  const [command, version] = process.argv.slice(2);

  if (command !== 'normalize' || version === undefined) {
    console.error('Usage: release-version.js normalize <version>');
    process.exit(2);
  }

  const normalized = normalizeReleaseVersion(version);
  if (normalized === null) {
    console.error(`Invalid release version: ${version}`);
    process.exit(1);
  }

  process.stdout.write(`${normalized}\n`);
}
