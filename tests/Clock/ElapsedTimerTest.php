<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Tests\Clock;

use Psr\Clock\ClockInterface;
use Rasuvaeff\Retry\Clock\ElapsedTimer;
use Rasuvaeff\Retry\Clock\FakeClock;
use Rasuvaeff\Retry\Clock\SystemClock;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(ElapsedTimer::class)]
final class ElapsedTimerTest
{
    public function systemClockIsMeasuredWithTheMonotonicSource(): void
    {
        $readings = [5_000_000_000, 5_002_999_999, 5_250_000_000];
        $timer = ElapsedTimer::start(
            clock: new SystemClock(),
            nanoseconds: static function () use (&$readings): int {
                return (int) array_shift($readings);
            },
        );

        Assert::same($timer->elapsedMs(), 2);
        Assert::same($timer->elapsedMs(), 250);
    }

    public function monotonicElapsedNeverGoesNegative(): void
    {
        $readings = [5_000_000_000, 4_000_000_000];
        $timer = ElapsedTimer::start(
            clock: new SystemClock(),
            nanoseconds: static function () use (&$readings): int {
                return (int) array_shift($readings);
            },
        );

        Assert::same($timer->elapsedMs(), 0);
    }

    public function defaultMonotonicSourceIsUsable(): void
    {
        $elapsed = ElapsedTimer::start(clock: new SystemClock())->elapsedMs();

        Assert::true($elapsed >= 0 && $elapsed < 60_000);
    }

    public function otherClocksAreReadAsIs(): void
    {
        $clock = new FakeClock();
        $timer = ElapsedTimer::start(
            clock: $clock,
            nanoseconds: static fn(): int => throw new \LogicException('monotonic source must not be read'),
        );

        $clock->advanceMs(ms: 1_500);

        Assert::same($timer->elapsedMs(), 1_500);
    }

    public function aClockSteppingBackwardsIsClampedToZero(): void
    {
        $clock = new class implements ClockInterface {
            private int $calls = 0;

            #[\Override]
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable($this->calls++ === 0 ? '2026-01-01 12:00:05' : '2026-01-01 12:00:00');
            }
        };

        Assert::same(ElapsedTimer::start(clock: $clock)->elapsedMs(), 0);
    }
}
