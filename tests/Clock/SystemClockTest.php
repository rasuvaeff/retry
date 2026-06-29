<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Tests\Clock;

use Rasuvaeff\Retry\Clock\SystemClock;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(SystemClock::class)]
final class SystemClockTest
{
    public function nowReturnsDateTimeImmutable(): void
    {
        $clock = new SystemClock();

        Assert::instanceOf($clock->now(), \DateTimeImmutable::class);
    }

    public function nowIsCloseToWallClock(): void
    {
        $clock = new SystemClock();
        $before = time();
        $now = $clock->now()->getTimestamp();
        $after = time();

        Assert::true($now >= $before && $now <= $after);
    }

    public function successiveCallsDoNotGoBackwards(): void
    {
        $clock = new SystemClock();

        $first = $clock->now()->getTimestamp();
        $second = $clock->now()->getTimestamp();

        Assert::true($second >= $first);
    }
}
