<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Tests\BackoffStrategy;

use Rasuvaeff\Retry\BackoffStrategy\ImmediateBackoff;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(ImmediateBackoff::class)]
final class ImmediateBackoffTest
{
    public function delayIsAlwaysZero(): void
    {
        $backoff = new ImmediateBackoff();

        Assert::same($backoff->delayMs(attempt: 1), 0);
        Assert::same($backoff->delayMs(attempt: 10), 0);
    }
}
