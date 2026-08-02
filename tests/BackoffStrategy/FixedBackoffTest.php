<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Tests\BackoffStrategy;

use Rasuvaeff\Retry\BackoffStrategy\FixedBackoff;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(FixedBackoff::class)]
final class FixedBackoffTest
{
    public function delayIsConstantAcrossAttempts(): void
    {
        $backoff = new FixedBackoff(delayMs: 250);

        Assert::same($backoff->delayMs(attempt: 1), 250);
        Assert::same($backoff->delayMs(attempt: 5), 250);
    }

    public function acceptsZeroDelay(): void
    {
        $backoff = new FixedBackoff(delayMs: 0);

        Assert::same($backoff->delayMs(attempt: 1), 0);
    }

    public function rejectsNegativeDelay(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Delay must be non-negative');

        new FixedBackoff(delayMs: -1);
    }
}
