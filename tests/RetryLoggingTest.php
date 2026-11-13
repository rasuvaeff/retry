<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Tests;

use Rasuvaeff\Retry\ExhaustionReason;
use Rasuvaeff\Retry\Log\RetryLog;
use Rasuvaeff\Retry\Retry;
use Rasuvaeff\Retry\RetryExhausted;
use Rasuvaeff\Retry\Sleeper\FakeSleeper;
use Rasuvaeff\Retry\Tests\Support\SpyLogger;
use Rasuvaeff\Retry\Tests\Support\ThrowingLogger;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(Retry::class)]
#[Covers(RetryLog::class)]
final class RetryLoggingTest
{
    public function logsWarningForEachRetryAndErrorOnExhaustion(): void
    {
        $logger = new SpyLogger();
        $failure = new \RuntimeException(message: 'down');

        try {
            Retry::fixed(delayMs: 10, maxAttempts: 3)
                ->withSleeper(sleeper: new FakeSleeper())
                ->withLogger(logger: $logger)
                ->run(operation: static function () use ($failure): never {
                    throw $failure;
                });
        } catch (RetryExhausted $exception) {
            Assert::same($exception->reason, ExhaustionReason::MaxAttempts);

            Assert::same(
                array_map(static fn(array $record): string => $record['level'], $logger->records),
                ['warning', 'warning', 'error'],
            );
            Assert::same(
                array_map(static fn(array $record): string => $record['message'], $logger->records),
                [
                    'operation failed on attempt {attempt}, retrying in {delay_ms} ms',
                    'operation failed on attempt {attempt}, retrying in {delay_ms} ms',
                    'operation retries exhausted after {attempts} attempts ({reason})',
                ],
            );
            Assert::same($logger->records[0]['context']['attempt'], 1);
            Assert::same($logger->records[0]['context']['delay_ms'], 10);
            Assert::same($logger->records[0]['context']['exception'], $failure);
            Assert::same($logger->records[1]['context']['attempt'], 2);
            Assert::same($logger->records[2]['context']['attempts'], 3);
            Assert::same($logger->records[2]['context']['reason'], 'MaxAttempts');
            Assert::same($logger->records[2]['context']['exception'], $failure);

            return;
        }

        throw new \RuntimeException(message: 'Expected RetryExhausted');
    }

    public function logsErrorWithTimeBudgetReason(): void
    {
        $logger = new SpyLogger();
        $failure = new \RuntimeException(message: 'down');

        try {
            Retry::fixed(delayMs: 10_000, maxAttempts: 5)
                ->withSleeper(sleeper: new FakeSleeper())
                ->stopAfterMs(budgetMs: 100)
                ->withLogger(logger: $logger)
                ->run(operation: static function () use ($failure): never {
                    throw $failure;
                });
        } catch (RetryExhausted $exception) {
            Assert::same($exception->reason, ExhaustionReason::TimeBudget);

            Assert::same(count($logger->records), 1);
            Assert::same($logger->records[0]['level'], 'error');
            Assert::same($logger->records[0]['context']['reason'], 'TimeBudget');
            Assert::same($logger->records[0]['context']['attempts'], 1);
            Assert::same($logger->records[0]['context']['exception'], $failure);

            return;
        }

        throw new \RuntimeException(message: 'Expected RetryExhausted');
    }

    public function clearingTheLoggerWithNullStopsLogging(): void
    {
        $configured = new SpyLogger();

        try {
            Retry::fixed(delayMs: 10, maxAttempts: 2)
                ->withSleeper(sleeper: new FakeSleeper())
                ->withLogger(logger: $configured)
                ->withLogger(logger: null)
                ->run(operation: static function (): never {
                    throw new \RuntimeException(message: 'down');
                });
        } catch (RetryExhausted) {
            Assert::same($configured->records, []);

            return;
        }

        throw new \RuntimeException(message: 'Expected RetryExhausted');
    }

    public function throwingLoggerDoesNotBreakTheRetryLoop(): void
    {
        $throwing = new ThrowingLogger();

        try {
            Retry::fixed(delayMs: 10, maxAttempts: 2)
                ->withSleeper(sleeper: new FakeSleeper())
                ->withLogger(logger: $throwing)
                ->run(operation: static function (): never {
                    throw new \RuntimeException(message: 'down');
                });
        } catch (RetryExhausted $exception) {
            // One throwing warning (retry) + one throwing error (exhausted):
            // both swallowed, the exhaustion outcome still delivered.
            Assert::same($exception->reason, ExhaustionReason::MaxAttempts);
            Assert::same($throwing->calls, 2);

            return;
        }

        throw new \RuntimeException(message: 'Expected RetryExhausted');
    }

    public function logsNothingOnSuccess(): void
    {
        $logger = new SpyLogger();

        $value = Retry::fixed(delayMs: 10, maxAttempts: 3)
            ->withSleeper(sleeper: new FakeSleeper())
            ->withLogger(logger: $logger)
            ->run(operation: static fn(): string => 'ok');

        Assert::same($value, 'ok');
        Assert::same($logger->records, []);
    }

    public function withLoggerReplacesThePreviouslyConfiguredLogger(): void
    {
        $first = new SpyLogger();
        $second = new SpyLogger();

        try {
            Retry::fixed(delayMs: 10, maxAttempts: 2)
                ->withSleeper(sleeper: new FakeSleeper())
                ->withLogger(logger: $first)
                ->withLogger(logger: $second)
                ->run(operation: static function (): never {
                    throw new \RuntimeException(message: 'down');
                });
        } catch (RetryExhausted) {
            Assert::same($first->records, []);
            Assert::same(
                array_map(static fn(array $record): string => $record['level'], $second->records),
                ['warning', 'error'],
            );

            return;
        }

        throw new \RuntimeException(message: 'Expected RetryExhausted');
    }
}
