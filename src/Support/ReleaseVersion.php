<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Support;

/**
 * Compares release versions according to Semantic Versioning precedence.
 */
final class ReleaseVersion
{
    private const PATTERN = '/^(?<major>0|[1-9][0-9]*)\.(?<minor>0|[1-9][0-9]*)\.(?<patch>0|[1-9][0-9]*)'.
        '(?:-(?<prerelease>(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*)'.
        '(?:\.(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*))*))?'.
        '(?:\+(?<build>[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?$/D';

    /**
     * @return -1|0|1
     */
    public static function compare(string $left, string $right): int
    {
        $leftVersion = self::parse($left);
        $rightVersion = self::parse($right);

        foreach (['major', 'minor', 'patch'] as $part) {
            $comparison = self::compareNumeric($leftVersion[$part], $rightVersion[$part]);
            if ($comparison !== 0) {
                return $comparison;
            }
        }

        $leftPrerelease = $leftVersion['prerelease'];
        $rightPrerelease = $rightVersion['prerelease'];
        if ($leftPrerelease === null || $rightPrerelease === null) {
            if ($leftPrerelease === $rightPrerelease) {
                return 0;
            }

            return $leftPrerelease === null ? 1 : -1;
        }

        $width = max(count($leftPrerelease), count($rightPrerelease));
        for ($index = 0; $index < $width; $index++) {
            $leftPart = $leftPrerelease[$index] ?? null;
            $rightPart = $rightPrerelease[$index] ?? null;
            if ($leftPart === null || $rightPart === null) {
                return $leftPart === null ? -1 : 1;
            }

            $leftNumeric = ctype_digit($leftPart);
            $rightNumeric = ctype_digit($rightPart);
            if ($leftNumeric && $rightNumeric) {
                $comparison = self::compareNumeric($leftPart, $rightPart);
            } elseif ($leftNumeric !== $rightNumeric) {
                $comparison = $leftNumeric ? -1 : 1;
            } else {
                $comparison = $leftPart <=> $rightPart;
            }

            if ($comparison !== 0) {
                return $comparison < 0 ? -1 : 1;
            }
        }

        return 0;
    }

    /**
     * @return array{major: string, minor: string, patch: string, prerelease: list<string>|null}
     */
    private static function parse(string $version): array
    {
        $version = trim($version);
        if (str_starts_with($version, 'v')) {
            $version = substr($version, 1);
        }

        if (preg_match(self::PATTERN, $version, $matches, PREG_UNMATCHED_AS_NULL) !== 1) {
            throw new \InvalidArgumentException(sprintf('invalid semantic version [%s]', $version));
        }

        return [
            'major' => $matches['major'],
            'minor' => $matches['minor'],
            'patch' => $matches['patch'],
            'prerelease' => $matches['prerelease'] === null
                ? null
                : explode('.', $matches['prerelease']),
        ];
    }

    /**
     * Compare arbitrarily large non-negative integer strings without casting.
     *
     * @return -1|0|1
     */
    private static function compareNumeric(string $left, string $right): int
    {
        if (strlen($left) !== strlen($right)) {
            return strlen($left) < strlen($right) ? -1 : 1;
        }
        if ($left === $right) {
            return 0;
        }

        return $left < $right ? -1 : 1;
    }
}
