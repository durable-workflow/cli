<?php

declare(strict_types=1);

namespace Tests\Support;

use DurableWorkflow\Cli\Support\ExitCode;
use DurableWorkflow\Cli\Support\ServerClient;
use DurableWorkflow\Cli\Support\ServerHttpException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ServerClientHttpFailureTest extends TestCase
{
    /**
     * @param array<string, mixed>|null $body
     */
    #[DataProvider('errorResponses')]
    public function test_http_failures_keep_their_status_and_diagnostics(
        int $status,
        string $content,
        string $message,
        ?array $body,
        int $exitCode,
    ): void {
        foreach (['/workflows', '/worker/register'] as $path) {
            $client = new ServerClient(
                baseUrl: 'http://example.test',
                namespace: 'default',
                http: new MockHttpClient(new MockResponse($content, ['http_code' => $status])),
            );

            try {
                $client->post($path);
                self::fail('An HTTP failure must not return a successful response.');
            } catch (ServerHttpException $exception) {
                self::assertSame($status, $exception->statusCode);
                self::assertSame($status, $exception->getCode());
                self::assertSame('Server error: '.$message, $exception->getMessage());
                self::assertSame($body, $exception->body);
                self::assertSame($exitCode, $exception->exitCode());
                self::assertSame($body['reason'] ?? null, $exception->reason());
                self::assertSame($body['validation_errors'] ?? $body['errors'] ?? null, $exception->validationErrors());
            }
        }
    }

    public static function errorResponses(): iterable
    {
        yield 'Laravel storage failure' => [500, '{"message":"Server Error"}', 'Server Error', ['message' => 'Server Error'], ExitCode::SERVER];
        yield 'HTML proxy failure' => [502, '<html><body>Bad Gateway</body></html>', 'HTTP 502', null, ExitCode::SERVER];
        yield 'empty proxy failure' => [503, '', 'HTTP 503', null, ExitCode::SERVER];
        yield 'JSON scalar failure' => [500, '"Server Error"', 'HTTP 500', null, ExitCode::SERVER];
        yield 'JSON null failure' => [500, 'null', 'HTTP 500', null, ExitCode::SERVER];
        yield 'malformed JSON failure' => [502, '{"message":', 'HTTP 502', null, ExitCode::SERVER];
        yield 'structured proxy error' => [502, '{"error":{"code":"upstream_unavailable"}}', 'HTTP 502', ['error' => ['code' => 'upstream_unavailable']], ExitCode::SERVER];
        yield 'authentication' => [401, '{"message":"Unauthenticated."}', 'Unauthenticated.', ['message' => 'Unauthenticated.'], ExitCode::AUTH];
        yield 'authorization' => [403, '{"message":"Forbidden."}', 'Forbidden.', ['message' => 'Forbidden.'], ExitCode::AUTH];
        yield 'not found' => [404, '{"message":"Workflow not found.","reason":"instance_not_found"}', 'Workflow not found.', ['message' => 'Workflow not found.', 'reason' => 'instance_not_found'], ExitCode::NOT_FOUND];
        yield 'validation' => [422, '{"errors":{"input":["The input is required."]}}', 'The input is required.', ['errors' => ['input' => ['The input is required.']]], ExitCode::INVALID];
        yield 'rejection' => [409, '{"rejection_reason":"workflow_already_running"}', 'Rejected: workflow_already_running', ['rejection_reason' => 'workflow_already_running'], ExitCode::INVALID];
        yield 'retryable backend' => [503, '{"message":"A required backend is temporarily unavailable.","reason":"backend_unavailable","retryable":true}', 'A required backend is temporarily unavailable.', ['message' => 'A required backend is temporarily unavailable.', 'reason' => 'backend_unavailable', 'retryable' => true], ExitCode::SERVER];
        yield 'broken error contract' => [500, '{"message":"Server Error","control_plane":{"operation":"start"}}', 'Server Error', ['message' => 'Server Error', 'control_plane' => ['operation' => 'start']], ExitCode::SERVER];
    }
}
