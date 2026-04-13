<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Support;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class ServerClient
{
    public const WORKER_PROTOCOL_VERSION = '1.0';

    public const CONTROL_PLANE_VERSION = '2';

    public const WORKER_PROTOCOL_HEADER = 'X-Durable-Workflow-Protocol-Version';

    public const CONTROL_PLANE_HEADER = 'X-Durable-Workflow-Control-Plane-Version';

    private HttpClientInterface $http;

    private string $baseUrl;

    private string $namespace;

    /**
     * @var array<string, mixed>|null
     */
    private ?array $clusterInfoCache = null;

    private ?ControlPlaneRequestContract $controlPlaneRequestContractCache = null;

    private ?string $controlPlaneRequestContractError = null;

    private bool $controlPlaneRequestContractResolved = false;

    public function __construct(
        ?string $baseUrl = null,
        ?string $token = null,
        ?string $namespace = null,
        ?HttpClientInterface $http = null,
    )
    {
        $this->baseUrl = rtrim($baseUrl ?? $this->resolveBaseUrl(), '/');
        $this->namespace = $namespace ?? $this->resolveNamespace();

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'X-Namespace' => $this->namespace,
            self::WORKER_PROTOCOL_HEADER => self::WORKER_PROTOCOL_VERSION,
            self::CONTROL_PLANE_HEADER => self::CONTROL_PLANE_VERSION,
        ];

        $token = $token ?? $this->resolveToken();
        if ($token) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        $this->http = $http ?? HttpClient::create([
            'base_uri' => $this->baseUrl,
            'headers' => $headers,
        ]);
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
        if (is_array($this->clusterInfoCache)) {
            return $this->clusterInfoCache;
        }

        $this->clusterInfoCache = $this->get('/cluster/info');

        return $this->clusterInfoCache;
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
        $response = $this->http->request($method, '/api'.$path, $options);

        return $this->decode($response, $method, $path);
    }

    private function decode(ResponseInterface $response, string $method, string $path): array
    {
        $statusCode = $response->getStatusCode();
        $body = $this->normalizePayload(
            $method,
            $path,
            json_decode($response->getContent(false), true) ?? [],
            $response,
            $statusCode,
        );

        if ($statusCode >= 400) {
            $message = $body['message']
                ?? $body['error']
                ?? $this->firstValidationMessage($body)
                ?? (isset($body['rejection_reason']) ? 'Rejected: '.$body['rejection_reason'] : null)
                ?? "HTTP {$statusCode}";
            throw new \RuntimeException("Server error: {$message}", $statusCode);
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
        if (! str_starts_with($path, '/workflows') || str_contains($path, '/history')) {
            return $body;
        }

        $version = $this->responseHeader($response, self::CONTROL_PLANE_HEADER);

        if ($version !== self::CONTROL_PLANE_VERSION) {
            throw new \RuntimeException(sprintf(
                'Server error: invalid control-plane response version for [%s]; expected [%s], got [%s].',
                $path,
                self::CONTROL_PLANE_VERSION,
                $version ?? 'missing',
            ));
        }

        $body = ControlPlaneResponse::normalize($method, $path, $body, $statusCode);

        $body['control_plane_version'] ??= $version;

        return $body;
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
