<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Support;

use DurableWorkflow\Cli\BuildInfo;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class ServerClient
{
    public const WORKER_PROTOCOL_VERSION = '1.0';

    public const CONTROL_PLANE_VERSION = '2';

    public const WORKER_PROTOCOL_HEADER = 'X-Durable-Workflow-Protocol-Version';

    public const CONTROL_PLANE_HEADER = 'X-Durable-Workflow-Control-Plane-Version';

    public static function isCompatibleWorkerProtocolVersion(string $clientVersion, string $serverVersion): bool
    {
        $client = self::splitWorkerProtocolVersion($clientVersion);
        $server = self::splitWorkerProtocolVersion($serverVersion);

        if ($client === null || $server === null) {
            return $clientVersion === $serverVersion;
        }

        return $client[0] === $server[0] && $client[1] <= $server[1];
    }

    private HttpClientInterface $http;

    private string $baseUrl;

    private string $namespace;

    private bool $tlsVerify;

    private string $workerProtocolVersion;

    private string $controlPlaneVersion;

    /**
     * @var array<string, string>
     */
    private array $defaultHeaders = [];

    /**
     * @var array<string, mixed>|null
     */
    private ?array $clusterInfoCache = null;

    private ?ControlPlaneRequestContract $controlPlaneRequestContractCache = null;

    private ?string $controlPlaneRequestContractError = null;

    private bool $controlPlaneRequestContractResolved = false;

    private bool $serverCompatibilityChecked = false;

    private bool $workerProtocolCompatibilityChecked = false;

    private ?float $requestTimeoutOverride = null;

    public function __construct(
        ?string $baseUrl = null,
        ?string $token = null,
        ?string $namespace = null,
        ?bool $tlsVerify = null,
        ?HttpClientInterface $http = null,
        ?float $timeout = null,
    )
    {
        $this->baseUrl = rtrim($baseUrl ?? $this->resolveBaseUrl(), '/');
        $this->namespace = $namespace ?? $this->resolveNamespace();
        $this->tlsVerify = $tlsVerify ?? true;

        $this->workerProtocolVersion = self::protocolVersionFromEnv(
            'DURABLE_WORKFLOW_WORKER_PROTOCOL_VERSION',
            self::WORKER_PROTOCOL_VERSION,
        );
        $this->controlPlaneVersion = self::protocolVersionFromEnv(
            'DURABLE_WORKFLOW_CONTROL_PLANE_VERSION',
            self::CONTROL_PLANE_VERSION,
        );

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'X-Namespace' => $this->namespace,
            self::WORKER_PROTOCOL_HEADER => $this->workerProtocolVersion,
            self::CONTROL_PLANE_HEADER => $this->controlPlaneVersion,
        ];

        $token = $token ?? $this->resolveToken();
        if ($token) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        $this->defaultHeaders = $headers;

        $options = [
            'base_uri' => $this->baseUrl,
            'verify_peer' => $this->tlsVerify,
            'verify_host' => $this->tlsVerify,
        ];

        if ($timeout !== null) {
            $options['timeout'] = $timeout;
        }

        $this->http = $http ?? HttpClient::create($options);
    }

    private static function protocolVersionFromEnv(string $name, string $default): string
    {
        $value = getenv($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }

    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, [
            'query' => $query,
        ]);
    }

    public function post(string $path, array $body = []): array
    {
        return $this->request('POST', $path, [
            'json' => $body,
        ]);
    }

    public function put(string $path, array $body = []): array
    {
        return $this->request('PUT', $path, [
            'json' => $body,
        ]);
    }

    public function delete(string $path): array
    {
        return $this->request('DELETE', $path);
    }

    /**
     * @return array<string, mixed>
     */
    public function clusterInfo(): array
    {
        $this->assertServerCompatibility();

        return $this->clusterInfoCache;
    }

    /**
     * Fetch `/api/cluster/info` without turning incompatible metadata into a
     * hard error. Diagnostic callers use this for soft compatibility warnings.
     *
     * @return array<string, mixed>
     */
    public function clusterInfoUnchecked(?float $timeout = null): array
    {
        if (is_array($this->clusterInfoCache)) {
            return $this->clusterInfoCache;
        }

        $previousTimeout = $this->requestTimeoutOverride;
        $this->requestTimeoutOverride = $timeout;

        try {
            // Keep this call polymorphic for injected diagnostic clients.
            $this->clusterInfoCache = $this->get('/cluster/info');
        } finally {
            $this->requestTimeoutOverride = $previousTimeout;
        }

        return $this->clusterInfoCache;
    }

    public function assertServerCompatibility(bool $includeWorkerProtocol = false, ?float $timeout = null): void
    {
        $this->clusterInfoUnchecked($timeout);
        $this->checkServerCompatibility();

        if ($includeWorkerProtocol) {
            $this->checkWorkerProtocolCompatibility();
        }
    }

    private function checkServerCompatibility(): void
    {
        if ($this->serverCompatibilityChecked || ! is_array($this->clusterInfoCache)) {
            return;
        }

        $this->serverCompatibilityChecked = true;

        $controlPlane = $this->clusterInfoCache['control_plane'] ?? null;

        if (! is_array($controlPlane)) {
            throw $this->compatibilityException(
                'missing control_plane manifest; expected control_plane.version '.$this->controlPlaneVersion.'.',
            );
        }

        $controlPlaneVersion = $controlPlane['version'] ?? null;
        if (! is_scalar($controlPlaneVersion) || trim((string) $controlPlaneVersion) !== $this->controlPlaneVersion) {
            throw $this->compatibilityException(sprintf(
                'unsupported control_plane.version [%s]; dw %s sends control_plane.version %s.',
                is_scalar($controlPlaneVersion) ? (string) $controlPlaneVersion : 'missing',
                BuildInfo::version(),
                $this->controlPlaneVersion,
            ));
        }

        if (! CompatibilityDiagnostics::cliVersionIsSupported($this->clusterInfoCache, BuildInfo::version())) {
            $supportedVersions = CompatibilityDiagnostics::cliSupportedVersions($this->clusterInfoCache) ?? 'unknown';
            throw $this->compatibilityException(sprintf(
                'dw %s is outside server-advertised cli supported_versions [%s].',
                BuildInfo::version(),
                $supportedVersions,
            ));
        }

        if (! ControlPlaneRequestContract::fromClusterInfo($this->clusterInfoCache) instanceof ControlPlaneRequestContract) {
            throw $this->compatibilityException(
                ControlPlaneRequestContract::compatibilityErrorFromClusterInfo($this->clusterInfoCache),
            );
        }
    }

    private function checkWorkerProtocolCompatibility(): void
    {
        if ($this->workerProtocolCompatibilityChecked || ! is_array($this->clusterInfoCache)) {
            return;
        }

        $this->workerProtocolCompatibilityChecked = true;

        $workerProtocol = $this->clusterInfoCache['worker_protocol'] ?? null;
        if (! is_array($workerProtocol)) {
            throw $this->compatibilityException(sprintf(
                'server did not advertise worker_protocol.version; worker commands send worker_protocol.version %s.',
                $this->workerProtocolVersion,
            ));
        }

        $version = $workerProtocol['version'] ?? null;
        if (
            ! is_scalar($version)
            || ! self::isCompatibleWorkerProtocolVersion($this->workerProtocolVersion, trim((string) $version))
        ) {
            throw $this->compatibilityException(sprintf(
                'unsupported worker_protocol.version [%s]; worker commands send worker_protocol.version %s.',
                is_scalar($version) ? (string) $version : 'missing',
                $this->workerProtocolVersion,
            ));
        }
    }

    private function compatibilityException(string $detail): CompatibilityException
    {
        $diagnostic = CompatibilityDiagnostics::failureDiagnostic(
            $this->clusterInfoCache ?? [],
            BuildInfo::version(),
            $detail,
            $this->controlPlaneVersion,
            $this->workerProtocolVersion,
        );

        return new CompatibilityException(
            CompatibilityDiagnostics::failureMessage($diagnostic),
            $diagnostic,
        );
    }

    public function controlPlaneRequestContract(): ?ControlPlaneRequestContract
    {
        if ($this->controlPlaneRequestContractResolved) {
            return $this->controlPlaneRequestContractCache;
        }

        $this->controlPlaneRequestContractResolved = true;
        $clusterInfo = $this->clusterInfo();
        $this->controlPlaneRequestContractCache = ControlPlaneRequestContract::fromClusterInfo($clusterInfo);
        $this->controlPlaneRequestContractError = $this->controlPlaneRequestContractCache instanceof ControlPlaneRequestContract
            ? null
            : ControlPlaneRequestContract::compatibilityErrorFromClusterInfo($clusterInfo);

        return $this->controlPlaneRequestContractCache;
    }

    public function requireControlPlaneRequestContract(): ControlPlaneRequestContract
    {
        $contract = $this->controlPlaneRequestContract();

        if ($contract instanceof ControlPlaneRequestContract) {
            return $contract;
        }

        throw new \RuntimeException(
            $this->controlPlaneRequestContractError
                ?? sprintf(
                    'Server compatibility error: missing control_plane.request_contract; expected %s v%d.',
                    ControlPlaneRequestContract::SCHEMA,
                    ControlPlaneRequestContract::VERSION,
                ),
        );
    }

    public function assertControlPlaneOptionValue(
        string $operation,
        string $field,
        ?string $value,
        ?string $optionName = null,
    ): void {
        $this->requireControlPlaneRequestContract()->assertCanonicalValue(
            operation: $operation,
            field: $field,
            value: $value,
            optionName: $optionName,
        );
    }

    private function request(string $method, string $path, array $options = []): array
    {
        if ($this->requestTimeoutOverride !== null) {
            $options['timeout'] ??= $this->requestTimeoutOverride;
            $options['max_duration'] ??= $this->requestTimeoutOverride;
        }

        $options['headers'] = array_merge($this->defaultHeaders, $options['headers'] ?? []);

        try {
            $response = $this->http->request($method, '/api'.$path, $options);

            return $this->decode($response, $method, $path);
        } catch (TimeoutExceptionInterface $e) {
            throw new TimeoutException("Request timed out: {$e->getMessage()}", 0, $e);
        } catch (TransportExceptionInterface $e) {
            throw new NetworkException("Server unreachable: {$e->getMessage()}", 0, $e);
        }
    }

    private function decode(ResponseInterface $response, string $method, string $path): array
    {
        try {
            $statusCode = $response->getStatusCode();
            $rawContent = $response->getContent(false);
        } catch (TimeoutExceptionInterface $e) {
            throw new TimeoutException("Request timed out: {$e->getMessage()}", 0, $e);
        } catch (TransportExceptionInterface $e) {
            throw new NetworkException("Server unreachable: {$e->getMessage()}", 0, $e);
        }

        $body = $this->normalizePayload(
            $method,
            $path,
            json_decode($rawContent, true) ?? [],
            $response,
            $statusCode,
        );

        if ($statusCode >= 400) {
            $message = $body['message']
                ?? $body['error']
                ?? $this->firstValidationMessage($body)
                ?? (isset($body['rejection_reason']) ? 'Rejected: '.$body['rejection_reason'] : null)
                ?? "HTTP {$statusCode}";
            throw new ServerHttpException("Server error: {$message}", $statusCode, body: $body === [] ? null : $body);
        }

        return $body;
    }

    private function normalizePayload(
        string $method,
        string $path,
        array $body,
        ResponseInterface $response,
        int $statusCode,
    ): array
    {
        if (self::isWorkerProtocolPath($path)) {
            $version = $this->responseHeader($response, self::WORKER_PROTOCOL_HEADER);

            if ($version === null || ! self::isCompatibleWorkerProtocolVersion($this->workerProtocolVersion, $version)) {
                throw new \RuntimeException(sprintf(
                    'Server error: invalid worker-protocol response version for [%s]; expected same-major server minor compatible with [%s], got [%s].',
                    $path,
                    $this->workerProtocolVersion,
                    $version ?? 'missing',
                ));
            }

            $bodyVersion = $body['protocol_version'] ?? null;
            if (
                ! is_scalar($bodyVersion)
                || trim((string) $bodyVersion) !== $version
                || ! self::isCompatibleWorkerProtocolVersion($this->workerProtocolVersion, trim((string) $bodyVersion))
            ) {
                throw new \RuntimeException(sprintf(
                    'Server error: invalid worker-protocol response body for [%s]; expected protocol_version same-major compatible with [%s].',
                    $path,
                    $this->workerProtocolVersion,
                ));
            }

            return $body;
        }

        if (! str_starts_with($path, '/workflows') || str_contains($path, '/history/export')) {
            return $body;
        }

        $version = $this->responseHeader($response, self::CONTROL_PLANE_HEADER);

        if ($version !== $this->controlPlaneVersion) {
            throw new \RuntimeException(sprintf(
                'Server error: invalid control-plane response version for [%s]; expected [%s], got [%s].',
                $path,
                $this->controlPlaneVersion,
                $version ?? 'missing',
            ));
        }

        $body = ControlPlaneResponse::normalize($method, $path, $body, $statusCode);

        $body['control_plane_version'] ??= $version;

        return $body;
    }

    private static function isWorkerProtocolPath(string $path): bool
    {
        return $path === '/worker' || str_starts_with($path, '/worker/');
    }

    private function responseHeader(ResponseInterface $response, string $header): ?string
    {
        $headers = $response->getHeaders(false);
        $values = $headers[strtolower($header)] ?? null;

        if (! is_array($values) || $values === []) {
            return null;
        }

        $value = trim((string) $values[0]);

        return $value === '' ? null : $value;
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private static function splitWorkerProtocolVersion(string $value): ?array
    {
        if (preg_match('/^\d+\.\d+$/', trim($value)) !== 1) {
            return null;
        }

        [$major, $minor] = explode('.', trim($value), 2);

        return [(int) $major, (int) $minor];
    }

    private function firstValidationMessage(array $body): ?string
    {
        foreach (['errors', 'validation_errors'] as $key) {
            $messages = $body[$key] ?? null;

            if (! is_array($messages)) {
                continue;
            }

            foreach ($messages as $fieldMessages) {
                if (! is_array($fieldMessages)) {
                    continue;
                }

                foreach ($fieldMessages as $message) {
                    if (is_string($message) && $message !== '') {
                        return $message;
                    }
                }
            }
        }

        return null;
    }

    private function resolveBaseUrl(): string
    {
        return $_ENV['DURABLE_WORKFLOW_SERVER_URL']
            ?? getenv('DURABLE_WORKFLOW_SERVER_URL') ?: 'http://localhost:8080';
    }

    private function resolveToken(): ?string
    {
        return $_ENV['DURABLE_WORKFLOW_AUTH_TOKEN']
            ?? getenv('DURABLE_WORKFLOW_AUTH_TOKEN') ?: null;
    }

    private function resolveNamespace(): string
    {
        return $_ENV['DURABLE_WORKFLOW_NAMESPACE']
            ?? getenv('DURABLE_WORKFLOW_NAMESPACE') ?: 'default';
    }
}
