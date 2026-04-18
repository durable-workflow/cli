<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[Group('integration')]
final class ServerSmokeTest extends TestCase
{
    private string $serverUrl;

    private ?string $adminToken;

    private ?string $operatorToken;

    protected function setUp(): void
    {
        parent::setUp();

        $explicitUrl = $this->env('DURABLE_WORKFLOW_CLI_SMOKE_SERVER_URL');
        $enabled = $this->env('DURABLE_WORKFLOW_CLI_SMOKE') === '1';

        if (! $enabled && $explicitUrl === null) {
            self::markTestSkipped(
                'Set DURABLE_WORKFLOW_CLI_SMOKE=1 or DURABLE_WORKFLOW_CLI_SMOKE_SERVER_URL to run the live server smoke test.',
            );
        }

        $this->serverUrl = rtrim(
            $explicitUrl
                ?? $this->env('DURABLE_WORKFLOW_SERVER_URL')
                ?? 'http://localhost:8080',
            '/',
        );

        $sharedToken = $this->env('DURABLE_WORKFLOW_AUTH_TOKEN');
        $this->adminToken = $this->env('DURABLE_WORKFLOW_CLI_SMOKE_ADMIN_TOKEN') ?? $sharedToken;
        $this->operatorToken = $this->env('DURABLE_WORKFLOW_CLI_SMOKE_OPERATOR_TOKEN') ?? $sharedToken ?? $this->adminToken;
    }

    public function test_cli_control_plane_smoke_against_running_server(): void
    {
        $suffix = strtolower(bin2hex(random_bytes(4)));
        $namespace = 'cli-smoke-'.$suffix;
        $workflowId = 'cli-smoke-wf-'.$suffix;
        $scheduleId = 'cli-smoke-schedule-'.$suffix;

        $health = $this->runDw(['server:health'], 'default', $this->operatorToken);
        self::assertStringContainsString('Server is', $health);

        $info = $this->runDw(['server:info'], 'default', $this->operatorToken);
        self::assertStringContainsString('Control Plane', $info);
        self::assertStringContainsString('Worker Protocol', $info);

        $createdNamespace = $this->runJsonDw([
            'namespace:create',
            $namespace,
            '--description=CLI smoke namespace',
            '--retention=7',
            '--json',
        ], 'default', $this->adminToken);

        self::assertSame($namespace, $createdNamespace['name'] ?? null);

        $listedNamespaces = $this->runJsonDw(['namespace:list', '--json'], $namespace, $this->adminToken);
        self::assertContains(
            $namespace,
            array_column($listedNamespaces['namespaces'] ?? [], 'name'),
        );

        $started = $this->runJsonDw([
            'workflow:start',
            '--type=cli.smoke.workflow',
            '--workflow-id='.$workflowId,
            '--task-queue=cli-smoke-workers',
            '--input=["Ada"]',
            '--memo={"source":"cli-smoke"}',
            '--search-attr=smoke='.$suffix,
            '--json',
        ], $namespace, $this->operatorToken);

        self::assertSame($workflowId, $started['workflow_id'] ?? null);
        self::assertSame('cli.smoke.workflow', $started['workflow_type'] ?? null);
        self::assertIsString($started['run_id'] ?? null);

        $listedWorkflows = $this->runJsonDw(['workflow:list', '--json'], $namespace, $this->operatorToken);
        self::assertContains(
            $workflowId,
            array_column($listedWorkflows['workflows'] ?? [], 'workflow_id'),
        );

        $described = $this->runJsonDw(['workflow:describe', $workflowId, '--json'], $namespace, $this->operatorToken);
        self::assertSame($workflowId, $described['workflow_id'] ?? null);
        self::assertSame($namespace, $described['namespace'] ?? null);
        self::assertSame($started['run_id'], $described['run_id'] ?? null);
        self::assertSame('cli-smoke', $described['memo']['source'] ?? null);

        $history = $this->runJsonDw([
            'workflow:history',
            $workflowId,
            (string) $started['run_id'],
            '--json',
        ], $namespace, $this->operatorToken);

        $historyEventTypes = array_column($history['events'] ?? [], 'event_type');
        self::assertContains('StartAccepted', $historyEventTypes);
        self::assertContains('WorkflowStarted', $historyEventTypes);

        $createdSchedule = $this->runJsonDw([
            'schedule:create',
            '--schedule-id='.$scheduleId,
            '--workflow-type=cli.smoke.scheduled',
            '--interval=PT1H',
            '--task-queue=cli-smoke-workers',
            '--paused',
            '--json',
        ], $namespace, $this->operatorToken);

        self::assertSame($scheduleId, $createdSchedule['schedule_id'] ?? null);

        $describedSchedule = $this->runJsonDw(['schedule:describe', $scheduleId, '--json'], $namespace, $this->operatorToken);
        self::assertSame($scheduleId, $describedSchedule['schedule_id'] ?? null);
        self::assertTrue((bool) ($describedSchedule['state']['paused'] ?? false));

        $listedSchedules = $this->runJsonDw(['schedule:list', '--json'], $namespace, $this->operatorToken);
        self::assertContains(
            $scheduleId,
            array_column($listedSchedules['schedules'] ?? [], 'schedule_id'),
        );

        $deletedSchedule = $this->runJsonDw(['schedule:delete', $scheduleId, '--json'], $namespace, $this->operatorToken);
        self::assertSame($scheduleId, $deletedSchedule['schedule_id'] ?? null);

        $terminated = $this->runJsonDw([
            'workflow:terminate',
            $workflowId,
            '--reason=CLI smoke cleanup',
            '--json',
        ], $namespace, $this->operatorToken);

        self::assertSame($workflowId, $terminated['workflow_id'] ?? null);
    }

    /**
     * @param list<string> $arguments
     */
    private function runJsonDw(array $arguments, string $namespace, ?string $token): array
    {
        $output = $this->runDw($arguments, $namespace, $token);

        try {
            $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            self::fail(sprintf(
                "Expected JSON from [%s], got:\n%s\n\n%s",
                implode(' ', $arguments),
                $output,
                $exception->getMessage(),
            ));
        }

        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param list<string> $arguments
     */
    private function runDw(array $arguments, string $namespace, ?string $token): string
    {
        $command = [
            PHP_BINARY,
            self::repoRoot().'/bin/dw',
            ...$arguments,
            '--server='.$this->serverUrl,
            '--namespace='.$namespace,
        ];

        if ($token !== null && $token !== '') {
            $command[] = '--token='.$token;
        }

        $process = new Process($command, self::repoRoot(), [
            'DURABLE_WORKFLOW_SERVER_URL' => $this->serverUrl,
            'DURABLE_WORKFLOW_NAMESPACE' => $namespace,
        ]);
        $process->setTimeout(30);
        $process->run();

        self::assertSame(
            0,
            $process->getExitCode(),
            sprintf(
                "dw command failed: %s\nstdout:\n%s\nstderr:\n%s",
                implode(' ', $arguments),
                $process->getOutput(),
                $process->getErrorOutput(),
            ),
        );

        return trim($process->getOutput());
    }

    private function env(string $name): ?string
    {
        $value = $_ENV[$name] ?? getenv($name);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function repoRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
