<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Tests\Sleeper;

use Rasuvaeff\Retry\Sleeper\SystemSleeper;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(SystemSleeper::class)]
final class SystemSleeperTest
{
    /**
     * Real wait is required here: nothing else distinguishes `$ms * 1000`
     * from `$ms / 1000` or a removed `usleep()` call. Golden rule #3 ("no
     * real sleeps") governs Retry-loop determinism (use FakeSleeper there);
     * SystemClockTest sets the precedent for testing a real-clock leaf
     * adapter against the wall clock.
     */
    public function actuallySleepsForRequestedDuration(): void
    {
        $sleeper = new SystemSleeper();
        $start = hrtime(as_number: true);

        $sleeper->sleepMs(ms: 30);

        $elapsedMs = (hrtime(as_number: true) - $start) / 1_000_000;

        Assert::true($elapsedMs >= 20.0);
        Assert::true($elapsedMs <= 1_000.0);
    }

    public function acceptsZeroDelayWithoutBlocking(): void
    {
        $sleeper = new SystemSleeper();
        $start = hrtime(as_number: true);

        $sleeper->sleepMs(ms: 0);

        $elapsedMs = (hrtime(as_number: true) - $start) / 1_000_000;

        Assert::true($elapsedMs < 100.0);
    }

    public function rejectsNegativeDelay(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Milliseconds must be non-negative');

        (new SystemSleeper())->sleepMs(ms: -1);
    }
}
