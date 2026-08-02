<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Tests\Sleeper;

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
}
