<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Tests;

use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\ContextCancelledException;
use Rasuvaeff\Context\ContextExpiredException;
use Rasuvaeff\Retry\Clock\FakeClock;
use Rasuvaeff\Retry\Retry;
use Rasuvaeff\Retry\Sleeper\ContextAwareSleeper;
use Rasuvaeff\Retry\Sleeper\FakeSleeper;
use Rasuvaeff\Retry\Tests\Support\FakeTimeSource;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(Retry::class)]
#[Covers(ContextAwareSleeper::class)]
final class RetryContextTest
{
    public function cancelledContextStopsBeforeFirstAttempt(): void
    {
        [$context, $controller] = Context::background(timeSource: new FakeTimeSource())->withCancel();
        $controller->cancel();
        $calls = 0;

        try {
            Retry::new()
                ->withContext(context: $context)
                ->withSleeper(sleeper: new FakeSleeper())
                ->run(operation: function () use (&$calls): string {
                    $calls++;

                    return 'ok';
                });
            Assert::fail('Expected ContextCancelledException');
        } catch (ContextCancelledException) {
            Assert::same($calls, 0);
        }
    }

    public function contextExceptionFromOperationIsNotRetried(): void
    {
        $sleeper = new FakeSleeper();
        $calls = 0;
        $thrown = new ContextCancelledException();

        try {
            Retry::fixed(delayMs: 10, maxAttempts: 3)
                ->withContext(context: Context::background(timeSource: new FakeTimeSource()))
                ->withSleeper(sleeper: $sleeper)
                ->run(operation: function () use (&$calls, $thrown): never {
                    $calls++;

                    throw $thrown;
                });
            Assert::fail('Expected ContextCancelledException');
        } catch (ContextCancelledException $exception) {
            Assert::same($exception, $thrown);
            Assert::same($calls, 1);
            Assert::same($sleeper->delays(), []);
        }
    }

    public function sleepsInFiftyMillisecondSlices(): void
    {
        $sleeper = new FakeSleeper();
        $calls = 0;

        $value = Retry::fixed(delayMs: 120, maxAttempts: 2)
            ->withContext(context: Context::background(timeSource: new FakeTimeSource()))
            ->withSleeper(sleeper: $sleeper)
            ->run(operation: function () use (&$calls): string {
                if (++$calls === 1) {
                    throw new \RuntimeException(message: 'down');
                }

                return 'ok';
            });

        Assert::same($value, 'ok');
        Assert::same($sleeper->delays(), [50, 50, 20]);
    }

    public function cancellationDuringSleepStopsTheLoop(): void
    {
        [$context, $controller] = Context::background(timeSource: new FakeTimeSource())->withCancel();
        $sleeper = FakeSleeper::advancing(
            clock: new FakeClock(),
            onSleep: static function () use ($controller): void {
                $controller->cancel();
            },
        );
        $calls = 0;

        try {
            Retry::fixed(delayMs: 500, maxAttempts: 3)
                ->withContext(context: $context)
                ->withSleeper(sleeper: $sleeper)
                ->run(operation: function () use (&$calls): never {
                    $calls++;

                    throw new \RuntimeException(message: 'down');
                });
            Assert::fail('Expected ContextCancelledException');
        } catch (ContextCancelledException) {
            Assert::same($calls, 1);
            Assert::same($sleeper->delays(), [50]);
        }
    }

    public function deadlineShortensTheLastSliceAndExpires(): void
    {
        $time = new FakeTimeSource();
        [$context] = Context::background(timeSource: $time)->withTimeout(seconds: 0.08);
        $sleeper = FakeSleeper::advancing(
            clock: new FakeClock(),
            onSleep: static function (int $ms) use ($time): void {
                $time->advanceMs(ms: $ms);
            },
        );
        $calls = 0;

        try {
            Retry::fixed(delayMs: 500, maxAttempts: 3)
                ->withContext(context: $context)
                ->withSleeper(sleeper: $sleeper)
                ->run(operation: function () use (&$calls): never {
                    $calls++;

                    throw new \RuntimeException(message: 'down');
                });
            Assert::fail('Expected ContextExpiredException');
        } catch (ContextExpiredException) {
            Assert::same($calls, 1);
            Assert::same($sleeper->delays(), [50, 30]);
        }
    }

    public function noContextSleepsTheWholeDelayAtOnce(): void
    {
        $sleeper = new FakeSleeper();
        $calls = 0;

        Retry::fixed(delayMs: 120, maxAttempts: 2)
            ->withSleeper(sleeper: $sleeper)
            ->run(operation: function () use (&$calls): string {
                if (++$calls === 1) {
                    throw new \RuntimeException(message: 'down');
                }

                return 'ok';
            });

        Assert::same($sleeper->delays(), [120]);
    }
}
