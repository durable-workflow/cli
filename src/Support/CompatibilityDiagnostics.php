<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Support;

final class CompatibilityDiagnostics
{
    public const NEXT_STEP = 'Upgrade dw, pin dw to a supported release, or connect to a compatible server.';

    /**
     * @param  array<string, mixed>  $clusterInfo
     * @return list<string>
     */
    public static function warnings(array $clusterInfo, string $cliVersion, bool $includeWorkerProtocol = true): array
    {
        $warnings = [];

        $checks = [
            self::controlPlaneWarning($clusterInfo),
            self::requestContractWarning($clusterInfo),
            self::authCompositionWarning($clusterInfo),
            ExternalTaskInputContract::warning($clusterInfo),
            ExternalTaskResultContract::warning($clusterInfo),
            self::clientCompatibilityAuthorityWarning($clusterInfo),
            self::cliVersionSupportWarning($clusterInfo, $cliVersion),
        ];

        if ($includeWorkerProtocol) {
            $checks[] = self::workerProtocolWarning($clusterInfo);
        }

        foreach ($checks as $warning) {
            if ($warning !== null) {
                $warnings[] = $warning;
            }
        }

        return $warnings;
    }

    /**
     * @param  array<string, mixed>  $clusterInfo
     */
    private static function controlPlaneWarning(array $clusterInfo): ?string
    {
        $controlPlane = $clusterInfo['control_plane'] ?? null;

        if (! is_array($controlPlane)) {
            return sprintf(
                'Compatibility warning: server did not advertise control_plane.version; dw CLI expects %s.',
                ServerClient::CONTROL_PLANE_VERSION,
            );
        }

        $version = $controlPlane['version'] ?? null;
        if (! is_scalar($version) || trim((string) $version) !== ServerClient::CONTROL_PLANE_VERSION) {
            return sprintf(
                'Compatibility warning: server advertises control_plane.version [%s]; dw CLI expects %s.',
                is_scalar($version) ? (string) $version : 'missing',
                ServerClient::CONTROL_PLANE_VERSION,
            );
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $clusterInfo
     */
    private static function authCompositionWarning(array $clusterInfo): ?string
    {
        if (! self::serverRequiresAuthComposition($clusterInfo) && ! isset($clusterInfo['auth_composition_contract'])) {
            return null;
        }

        $expected = sprintf('%s v%d', AuthCompositionContract::SCHEMA, AuthCompositionContract::VERSION);
        $contract = $clusterInfo['auth_composition_contract'] ?? null;

        if (! is_array($contract)) {
            return sprintf(
                'Compatibility warning: server did not advertise auth_composition_contract; dw CLI expects %s.',
                $expected,
            );
        }

        $schema = $contract['schema'] ?? null;
        $version = $contract['version'] ?? null;
        $versionMatches = is_int($version) || (is_string($version) && ctype_digit($version))
            ? (int) $version === AuthCompositionContract::VERSION
            : false;

        if ($schema !== AuthCompositionContract::SCHEMA || ! $versionMatches) {
            return sprintf(
                'Compatibility warning: server advertises auth_composition_contract [%s v%s]; dw CLI expects %s.',
                is_scalar($schema) ? (string) $schema : 'missing',
                is_scalar($version) ? (string) $version : 'missing',
                $expected,
            );
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $clusterInfo
     */
    private static function serverRequiresAuthComposition(array $clusterInfo): bool
    {
        $compatibility = $clusterInfo['client_compatibility'] ?? null;
        if (! is_array($compatibility)) {
            return false;
        }

        $requiredProtocols = $compatibility['required_protocols'] ?? null;
        if (is_array($requiredProtocols) && array_key_exists('auth_composition', $requiredProtocols)) {
            return true;
        }

        $clients = $compatibility['clients'] ?? null;
        if (! is_array($clients)) {
            return false;
        }

        $cli = $clients['cli'] ?? null;
        if (! is_array($cli)) {
            return false;
        }

        $requires = $cli['requires'] ?? null;
        if (! is_array($requires)) {
            return false;
        }

        foreach ($requires as $requirement) {
            if (is_string($requirement) && str_starts_with($requirement, 'auth_composition.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $clusterInfo
     */
    private static function requestContractWarning(array $clusterInfo): ?string
    {
        $controlPlane = $clusterInfo['control_plane'] ?? null;
        if (! is_array($controlPlane)) {
            return null;
        }

        $requestContract = $controlPlane['request_contract'] ?? null;
        $expected = sprintf('%s v%d', ControlPlaneRequestContract::SCHEMA, ControlPlaneRequestContract::VERSION);

        if (! is_array($requestContract)) {
            return sprintf(
                'Compatibility warning: server did not advertise control_plane.request_contract; dw CLI expects %s.',
                $expected,
            );
        }

        $schema = $requestContract['schema'] ?? null;
        $version = $requestContract['version'] ?? null;
        $versionMatches = is_int($version) || (is_string($version) && ctype_digit($version))
            ? (int) $version === ControlPlaneRequestContract::VERSION
            : false;

        if ($schema !== ControlPlaneRequestContract::SCHEMA || ! $versionMatches) {
            return sprintf(
                'Compatibility warning: server advertises control_plane.request_contract [%s v%s]; dw CLI expects %s.',
                is_scalar($schema) ? (string) $schema : 'missing',
                is_scalar($version) ? (string) $version : 'missing',
                $expected,
            );
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $clusterInfo
     */
    private static function workerProtocolWarning(array $clusterInfo): ?string
    {
        $workerProtocol = $clusterInfo['worker_protocol'] ?? null;

        if (! is_array($workerProtocol)) {
            return sprintf(
                'Compatibility warning: server did not advertise worker_protocol.version; worker commands expect %s.',
                ServerClient::WORKER_PROTOCOL_VERSION,
            );
        }

        $version = $workerProtocol['version'] ?? null;
        if (
            ! is_scalar($version)
            || ! ServerClient::isCompatibleWorkerProtocolVersion(
                ServerClient::WORKER_PROTOCOL_VERSION,
                trim((string) $version),
            )
        ) {
            return sprintf(
                'Compatibility warning: server advertises worker_protocol.version [%s]; worker commands support protocol %s on compatible same-major server minors.',
                is_scalar($version) ? (string) $version : 'missing',
                ServerClient::WORKER_PROTOCOL_VERSION,
            );
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $clusterInfo
     */
    private static function clientCompatibilityAuthorityWarning(array $clusterInfo): ?string
    {
        $compatibility = $clusterInfo['client_compatibility'] ?? null;
        if (! is_array($compatibility)) {
            return null;
        }

        $authority = $compatibility['authority'] ?? null;
        if ($authority !== 'protocol_manifests') {
            return sprintf(
                'Compatibility warning: server client_compatibility authority is [%s]; dw CLI expects protocol_manifests.',
                is_scalar($authority) ? (string) $authority : 'missing',
            );
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $clusterInfo
     */
    private static function cliVersionSupportWarning(array $clusterInfo, string $cliVersion): ?string
    {
        $compatibility = $clusterInfo['client_compatibility'] ?? null;
        if (! is_array($compatibility)) {
            return null;
        }

        $clients = $compatibility['clients'] ?? null;
        if (! is_array($clients)) {
            return null;
        }

        $cli = $clients['cli'] ?? null;
        if (! is_array($cli)) {
            return null;
        }

        $supportedVersions = $cli['supported_versions'] ?? null;
        if (! is_string($supportedVersions) || trim($supportedVersions) === '') {
            return null;
        }

        if (self::matchesVersionConstraint($cliVersion, $supportedVersions)) {
            return null;
        }

        return sprintf(
            'Compatibility warning: dw %s is outside server-advertised cli supported_versions [%s] for server %s. Compatibility window: %s. Next step: upgrade dw, pin dw to a supported release, or connect to a compatible server.',
            $cliVersion,
            $supportedVersions,
            self::serverVersion($clusterInfo),
            $supportedVersions,
        );
    }

    /**
     * @param  array<string, mixed>  $clusterInfo
     */
    public static function serverVersion(array $clusterInfo): string
    {
        foreach ([
            $clusterInfo['version'] ?? null,
            $clusterInfo['server_version'] ?? null,
            is_array($clusterInfo['server'] ?? null) ? ($clusterInfo['server']['version'] ?? null) : null,
        ] as $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return 'unknown';
    }

    /**
     * @param  array<string, mixed>  $clusterInfo
     */
    public static function cliSupportedVersions(array $clusterInfo): ?string
    {
        $compatibility = $clusterInfo['client_compatibility'] ?? null;
        if (! is_array($compatibility)) {
            return null;
        }

        $clients = $compatibility['clients'] ?? null;
        if (! is_array($clients)) {
            return null;
        }

        $cli = $clients['cli'] ?? null;
        if (! is_array($cli)) {
            return null;
        }

        $supportedVersions = $cli['supported_versions'] ?? null;

        return is_string($supportedVersions) && trim($supportedVersions) !== ''
            ? trim($supportedVersions)
            : null;
    }

    /**
     * @param  array<string, mixed>  $clusterInfo
     */
    public static function cliVersionIsSupported(array $clusterInfo, string $cliVersion): bool
    {
        $supportedVersions = self::cliSupportedVersions($clusterInfo);

        return $supportedVersions === null
            || self::matchesVersionConstraint($cliVersion, $supportedVersions);
    }

    /**
     * @param  array<string, mixed>  $clusterInfo
     * @return array<string, mixed>
     */
    public static function failureDiagnostic(
        array $clusterInfo,
        string $cliVersion,
        string $detail,
        ?string $clientControlPlaneVersion = null,
        ?string $clientWorkerProtocolVersion = null,
    ): array {
        $controlPlane = is_array($clusterInfo['control_plane'] ?? null) ? $clusterInfo['control_plane'] : [];
        $workerProtocol = is_array($clusterInfo['worker_protocol'] ?? null) ? $clusterInfo['worker_protocol'] : [];
        $supportedVersions = self::cliSupportedVersions($clusterInfo);

        return [
            'cli_version' => $cliVersion,
            'server_version' => self::serverVersion($clusterInfo),
            'compatibility_window' => self::compatibilityWindow($clusterInfo, $supportedVersions),
            'server_supported_cli_versions' => $supportedVersions,
            'control_plane' => [
                'client_version' => $clientControlPlaneVersion,
                'server_version' => self::scalarString($controlPlane['version'] ?? null),
            ],
            'worker_protocol' => [
                'client_version' => $clientWorkerProtocolVersion,
                'server_version' => self::scalarString($workerProtocol['version'] ?? null),
            ],
            'next_step' => self::NEXT_STEP,
            'detail' => $detail,
        ];
    }

    /**
     * @param  array<string, mixed>  $diagnostic
     */
    public static function failureMessage(array $diagnostic): string
    {
        return sprintf(
            'Server compatibility error: refusing before the requested operation because dw %s cannot safely interoperate with server %s. Compatibility window: %s. Next step: %s Detail: %s',
            self::scalarString($diagnostic['cli_version'] ?? null) ?? 'unknown',
            self::scalarString($diagnostic['server_version'] ?? null) ?? 'unknown',
            self::scalarString($diagnostic['compatibility_window'] ?? null) ?? 'unknown',
            self::scalarString($diagnostic['next_step'] ?? null) ?? self::NEXT_STEP,
            self::scalarString($diagnostic['detail'] ?? null) ?? 'compatibility metadata is incomplete',
        );
    }

    /**
     * @param  array<string, mixed>  $clusterInfo
     */
    private static function compatibilityWindow(array $clusterInfo, ?string $supportedVersions): string
    {
        $controlPlane = is_array($clusterInfo['control_plane'] ?? null) ? $clusterInfo['control_plane'] : [];
        $workerProtocol = is_array($clusterInfo['worker_protocol'] ?? null) ? $clusterInfo['worker_protocol'] : [];
        $window = [
            $supportedVersions === null
                ? 'server did not advertise cli supported_versions'
                : 'cli '.$supportedVersions,
        ];

        $controlPlaneVersion = self::scalarString($controlPlane['version'] ?? null);
        if ($controlPlaneVersion !== null) {
            $window[] = 'control-plane version '.$controlPlaneVersion;
        }

        $workerProtocolVersion = self::scalarString($workerProtocol['version'] ?? null);
        if ($workerProtocolVersion !== null) {
            $window[] = 'worker protocol same-major <= '.$workerProtocolVersion;
        }

        return implode('; ', $window);
    }

    private static function scalarString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private static function matchesVersionConstraint(string $version, string $constraint): bool
    {
        $versionParts = self::versionParts($version);
        if ($versionParts === null) {
            return false;
        }

        foreach (preg_split('/\\s*\\|\\|\\s*/', trim($constraint)) ?: [] as $alternative) {
            if ($alternative === '') {
                continue;
            }

            $requirements = array_filter(
                array_map('trim', explode(',', $alternative)),
                static fn (string $part): bool => $part !== '',
            );

            if ($requirements === []) {
                continue;
            }

            $matches = true;
            foreach ($requirements as $requirement) {
                if (! self::matchesSingleRequirement($version, $versionParts, $requirement)) {
                    $matches = false;
                    break;
                }
            }

            if ($matches) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{0: int, 1: int, 2: int}  $versionParts
     */
    private static function matchesSingleRequirement(string $version, array $versionParts, string $requirement): bool
    {
        if ($requirement === '*' || $requirement === 'x') {
            return true;
        }

        if (preg_match('/^v?(\\d+)\\.(\\d+)\\.(?:x|\\*)$/', $requirement, $matches) === 1) {
            return $versionParts[0] === (int) $matches[1]
                && $versionParts[1] === (int) $matches[2];
        }

        if (preg_match('/^v?(\\d+)\\.(?:x|\\*)$/', $requirement, $matches) === 1) {
            return $versionParts[0] === (int) $matches[1];
        }

        if (preg_match('/^(<=|>=|<|>|=)?\\s*v?(\\d+)(?:\\.(\\d+))?(?:\\.(\\d+))?((?:-[0-9A-Za-z][0-9A-Za-z.-]*)?)$/', $requirement, $matches) !== 1) {
            return false;
        }

        $operator = $matches[1] !== '' ? $matches[1] : '=';
        $requiredParts = [
            (int) $matches[2],
            isset($matches[3]) && $matches[3] !== '' ? (int) $matches[3] : 0,
            isset($matches[4]) && $matches[4] !== '' ? (int) $matches[4] : 0,
        ];
        $requiredVersion = implode('.', $requiredParts).($matches[5] ?? '');
        $normalizedVersion = ltrim(trim($version), 'v');

        if (($matches[5] ?? '') !== '' || str_contains($normalizedVersion, '-')) {
            return version_compare($normalizedVersion, $requiredVersion, $operator);
        }

        $comparison = self::compareVersionParts($versionParts, $requiredParts);

        return match ($operator) {
            '<' => $comparison < 0,
            '<=' => $comparison <= 0,
            '>' => $comparison > 0,
            '>=' => $comparison >= 0,
            default => $comparison === 0,
        };
    }

    /**
     * @return array{0: int, 1: int, 2: int}|null
     */
    private static function versionParts(string $version): ?array
    {
        if (preg_match('/^v?(\\d+)(?:\\.(\\d+))?(?:\\.(\\d+))?/', trim($version), $matches) !== 1) {
            return null;
        }

        return [
            (int) $matches[1],
            isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : 0,
            isset($matches[3]) && $matches[3] !== '' ? (int) $matches[3] : 0,
        ];
    }

    /**
     * @param  array{0: int, 1: int, 2: int}  $left
     * @param  array{0: int, 1: int, 2: int}  $right
     */
    private static function compareVersionParts(array $left, array $right): int
    {
        return $left <=> $right;
    }
}
