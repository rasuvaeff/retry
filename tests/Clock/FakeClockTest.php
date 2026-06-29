<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Tests\Clock;

use Rasuvaeff\Retry\Clock\FakeClock;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(FakeClock::class)]
final class FakeClockTest
{
    public function defaultNowIsStableEpoch(): void
    {
        $clock = new FakeClock();

        Assert::same($clock->now()->format('Y-m-d\TH:i:s.uP'), '2025-01-01T00:00:00.000000+00:00');
    }

    public function customNowIsRespected(): void
    {
        $fixed = '2030-12-31T23:59:59.000000+00:00';
        $clock = new FakeClock(now: new \DateTimeImmutable($fixed));

        Assert::same($clock->now()->format('Y-m-d\TH:i:s.uP'), $fixed);
    }

    public function successiveCallsReturnSameInstantUntilAdvanced(): void
    {
        $clock = new FakeClock();
        $format = 'Y-m-d\TH:i:s.uP';

        Assert::same($clock->now()->format($format), $clock->now()->format($format));
    }

    public function advanceMsMovesForwardByGivenMilliseconds(): void
    {
        $clock = new FakeClock();
        $clock->advanceMs(ms: 1_500);

        Assert::same($clock->now()->format('Y-m-d\TH:i:s.uP'), '2025-01-01T00:00:01.500000+00:00');
    }

    public function advanceMsIsCumulative(): void
    {
        $clock = new FakeClock();
        $clock->advanceMs(ms: 250);
        $clock->advanceMs(ms: 750);

        Assert::same($clock->now()->format('Y-m-d\TH:i:s.uP'), '2025-01-01T00:00:01.000000+00:00');
    }

    public function advanceMsZeroLeavesTimeUnchanged(): void
    {
        $clock = new FakeClock();
        $format = 'Y-m-d\TH:i:s.uP';
        $before = $clock->now()->format($format);

        $clock->advanceMs(ms: 0);

        Assert::same($clock->now()->format($format), $before);
    }

    public function advanceMsRejectsNegativeValue(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Milliseconds must be non-negative');

        (new FakeClock())->advanceMs(ms: -1);
    }
}
