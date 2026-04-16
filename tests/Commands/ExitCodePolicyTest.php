<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\BaseCommand;
use DurableWorkflow\Cli\Support\ExitCode;
use DurableWorkflow\Cli\Support\NetworkException;
use DurableWorkflow\Cli\Support\ServerClient;
use DurableWorkflow\Cli\Support\ServerHttpException;
use DurableWorkflow\Cli\Support\TimeoutException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

class ExitCodePolicyTest extends TestCase
{
    /**
     * @dataProvider httpStatusCases
     */
    public function test_http_status_maps_to_expected_exit_code(int $status, int $expected): void
    {
        self::assertSame($expected, ExitCode::fromHttpStatus($status));
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function httpStatusCases(): iterable
    {
        yield '401 unauthorized → AUTH' => [401, ExitCode::AUTH];
        yield '403 forbidden → AUTH' => [403, ExitCode::AUTH];
        yield '404 not found → NOT_FOUND' => [404, ExitCode::NOT_FOUND];
        yield '408 request timeout → TIMEOUT' => [408, ExitCode::TIMEOUT];
        yield '400 bad request → INVALID' => [400, ExitCode::INVALID];
        yield '422 unprocessable → INVALID' => [422, ExitCode::INVALID];
        yield '500 server error → SERVER' => [500, ExitCode::SERVER];
        yield '503 service unavailable → SERVER' => [503, ExitCode::SERVER];
        yield '200 ok → FAILURE (non-error status)' => [200, ExitCode::FAILURE];
    }

    public function test_base_command_translates_network_exception_to_network_exit_code(): void
    {
        $command = new ThrowingBaseCommand(new NetworkException('Connection refused'));
        $tester = new CommandTester($command);

        self::assertSame(ExitCode::NETWORK, $tester->execute([]));
        self::assertStringContainsString('Connection refused', $tester->getDisplay());
    }

    public function test_base_command_translates_timeout_exception_to_timeout_exit_code(): void
    {
        $command = new ThrowingBaseCommand(new TimeoutException('deadline exceeded'));
        $tester = new CommandTester($command);

        self::assertSame(ExitCode::TIMEOUT, $tester->execute([]));
        self::assertStringContainsString('deadline exceeded', $tester->getDisplay());
    }

    public function test_base_command_translates_auth_http_status_to_auth_exit_code(): void
    {
        $command = new ThrowingBaseCommand(new ServerHttpException('Unauthorized', 401));
        $tester = new CommandTester($command);

        self::assertSame(ExitCode::AUTH, $tester->execute([]));
        self::assertStringContainsString('Unauthorized', $tester->getDisplay());
    }

    public function test_base_command_translates_not_found_http_status_to_not_found_exit_code(): void
    {
        $command = new ThrowingBaseCommand(new ServerHttpException('Workflow not found', 404));
        $tester = new CommandTester($command);

        self::assertSame(ExitCode::NOT_FOUND, $tester->execute([]));
    }

    public function test_base_command_translates_server_http_status_to_server_exit_code(): void
    {
        $command = new ThrowingBaseCommand(new ServerHttpException('internal', 502));
        $tester = new CommandTester($command);

        self::assertSame(ExitCode::SERVER, $tester->execute([]));
    }

    public function test_base_command_translates_validation_http_status_to_invalid_exit_code(): void
    {
        $command = new ThrowingBaseCommand(new ServerHttpException('bad input', 422));
        $tester = new CommandTester($command);

        self::assertSame(ExitCode::INVALID, $tester->execute([]));
    }

    public function test_base_command_translates_unexpected_throwable_to_failure_exit_code(): void
    {
        $command = new ThrowingBaseCommand(new \RuntimeException('boom'));
        $tester = new CommandTester($command);

        self::assertSame(ExitCode::FAILURE, $tester->execute([]));
        self::assertStringContainsString('boom', $tester->getDisplay());
    }

    public function test_exit_codes_remain_distinct(): void
    {
        $values = [
            ExitCode::SUCCESS,
            ExitCode::FAILURE,
            ExitCode::INVALID,
            ExitCode::NETWORK,
            ExitCode::AUTH,
            ExitCode::NOT_FOUND,
            ExitCode::SERVER,
            ExitCode::TIMEOUT,
        ];

        self::assertCount(count($values), array_unique($values), 'exit codes must remain distinct');
    }

    public function test_symfony_canonical_codes_preserved(): void
    {
        self::assertSame(Command::SUCCESS, ExitCode::SUCCESS);
        self::assertSame(Command::FAILURE, ExitCode::FAILURE);
        self::assertSame(Command::INVALID, ExitCode::INVALID);
    }
}

class ThrowingBaseCommand extends BaseCommand
{
    public function __construct(private readonly \Throwable $toThrow)
    {
        parent::__construct('throwing');
    }

    protected function client(InputInterface $input): ServerClient
    {
        throw new \LogicException('not used');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        throw $this->toThrow;
    }
}
