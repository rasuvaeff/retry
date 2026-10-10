<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Tests\Sleeper;

use Rasuvaeff\Retry\Clock\FakeClock;
use Rasuvaeff\Retry\ExhaustionReason;
use Rasuvaeff\Retry\Retry;
use Rasuvaeff\Retry\RetryExhausted;
use Rasuvaeff\Retry\Sleeper\FakeSleeper;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(FakeSleeper::class)]
final class FakeSleeperTest
{
    public function startsWithNoDelays(): void
    {
        Assert::same((new FakeSleeper())->delays(), []);
    }

    public function recordsDelaysInOrder(): void
    {
        $sleeper = new FakeSleeper();

        $sleeper->sleepMs(ms: 100);
        $sleeper->sleepMs(ms: 200);

        Assert::same($sleeper->delays(), [100, 200]);
    }

    public function acceptsZeroDelay(): void
    {
        $sleeper = new FakeSleeper();

        $sleeper->sleepMs(ms: 0);

        Assert::same($sleeper->delays(), [0]);
    }

    public function rejectsNegativeDelay(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Milliseconds must be non-negative');

        (new FakeSleeper())->sleepMs(ms: -1);
    }

    public function plainSleeperDoesNotNeedAClock(): void
    {
        $sleeper = new FakeSleeper();

        $sleeper->sleepMs(ms: 50);

        Assert::same($sleeper->delays(), [50]);
    }

    public function advancingSleeperMovesTheClockByEachDelay(): void
    {
        $clock = new FakeClock();
        $start = $clock->now();
        $sleeper = FakeSleeper::advancing($clock);

        $sleeper->sleepMs(ms: 250);
        $sleeper->sleepMs(ms: 1_500);

        Assert::same($sleeper->delays(), [250, 1_500]);
        Assert::same(
            $clock->now()->format('U.u'),
            $start->modify('+1750 milliseconds')->format('U.u'),
        );
    }

    public function advancingSleeperRunsTheHookAfterTheClockMoved(): void
    {
        $clock = new FakeClock();
        $start = $clock->now();
        $seen = [];
        $sleeper = FakeSleeper::advancing(
            clock: $clock,
            onSleep: static function (int $ms) use ($clock, $start, &$seen): void {
                $seen[] = [$ms, (int) $clock->now()->format('Uv') - (int) $start->format('Uv')];
            },
        );

        $sleeper->sleepMs(ms: 100);
        $sleeper->sleepMs(ms: 0);

        Assert::same($seen, [[100, 100], [0, 100]]);
    }

    public function advancingSleeperRejectsNegativeDelayWithoutMovingTheClock(): void
    {
        $clock = new FakeClock();
        $before = $clock->now();
        $calls = 0;
        $sleeper = FakeSleeper::advancing(
            clock: $clock,
            onSleep: static function () use (&$calls): void {
                $calls++;
            },
        );

        try {
            $sleeper->sleepMs(ms: -1);
        } catch (\InvalidArgumentException) {
        }

        Assert::same($clock->now(), $before);
        Assert::same($sleeper->delays(), []);
        Assert::same($calls, 0);
    }

    public function advancingSleeperDrivesARetryTimeBudget(): void
    {
        $clock = new FakeClock();
        $sleeper = FakeSleeper::advancing($clock);
        $retry = Retry::fixed(delayMs: 400, maxAttempts: 10)
            ->stopAfterMs(budgetMs: 1_000)
            ->withSleeper($sleeper)
            ->withClock($clock);

        try {
            $retry->run(static fn(): never => throw new \RuntimeException('down'));
        } catch (RetryExhausted $e) {
            Assert::same($e->reason, ExhaustionReason::TimeBudget);
        }

        Assert::same($sleeper->delays(), [400, 400]);
    }
}
