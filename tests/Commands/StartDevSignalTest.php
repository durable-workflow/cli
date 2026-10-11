<?php
declare(strict_types=1);

namespace Tests\Commands;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\StartDevSignalFixture;

final class StartDevSignalTest extends TestCase
{
    #[DataProvider('signals')]
    public function test_interruption_stops_the_active_child(string $database, int $signal): void
    {
        $result = StartDevSignalFixture::run([PHP_BINARY, dirname(__DIR__, 2).'/bin/dw'], $database, $signal);

        self::assertSame($signal, $result['forwarded_signal'], $result['output']);
        self::assertFalse($result['child_alive']);
        self::assertSame(128 + $signal, $result['exit_code']);
        if ($database === 'mysql') {
            self::assertStringNotContainsString('Starting Laravel HTTP server', $result['output']);
        }
    }

    public static function signals(): iterable
    {
        yield 'HTTP SIGINT' => ['sqlite', 2];
        yield 'HTTP SIGTERM' => ['sqlite', 15];
        yield 'Compose SIGINT' => ['mysql', 2];
        yield 'Compose SIGTERM' => ['mysql', 15];
    }

    public function test_an_ignored_signal_cannot_keep_the_child_alive_past_the_budget(): void
    {
        $result = StartDevSignalFixture::run([PHP_BINARY, dirname(__DIR__, 2).'/bin/dw'], 'sqlite', 15, true);

        self::assertNull($result['forwarded_signal']);
        self::assertFalse($result['child_alive']);
        self::assertSame(143, $result['exit_code']);
        self::assertGreaterThanOrEqual(5, $result['stop_seconds']);
        self::assertLessThan(7, $result['stop_seconds']);
    }

    public function test_a_second_interrupt_preserves_the_original_cleanup_deadline(): void
    {
        $result = StartDevSignalFixture::run([PHP_BINARY, dirname(__DIR__, 2).'/bin/dw'], 'sqlite', 2, true, 15);

        self::assertNull($result['forwarded_signal']);
        self::assertFalse($result['child_alive']);
        self::assertSame(130, $result['exit_code']);
        self::assertGreaterThanOrEqual(5, $result['stop_seconds']);
        self::assertLessThan(6.5, $result['stop_seconds']);
    }
}
