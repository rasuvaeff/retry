<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Tests;

use Rasuvaeff\Retry\AttemptRecord;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(AttemptRecord::class)]
final class AttemptRecordTest
{
    public function acceptsNullDelay(): void
    {
        $record = new AttemptRecord(attempt: 1, delayMs: null, elapsedMs: 0, exception: new \RuntimeException());

        Assert::null($record->delayMs);
    }

    public function acceptsZeroDelay(): void
    {
        $record = new AttemptRecord(attempt: 1, delayMs: 0, elapsedMs: 0, exception: new \RuntimeException());

        Assert::same($record->delayMs, 0);
    }

    public function rejectsNegativeAttempt(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Attempt must be greater than or equal to 1');

        new AttemptRecord(attempt: 0, delayMs: 0, elapsedMs: 0, exception: new \RuntimeException());
    }

    public function rejectsNegativeDelay(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Delay must be non-negative');

        new AttemptRecord(attempt: 1, delayMs: -1, elapsedMs: 0, exception: new \RuntimeException());
    }

    public function rejectsNegativeElapsed(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Elapsed must be non-negative');

        new AttemptRecord(attempt: 1, delayMs: 0, elapsedMs: -1, exception: new \RuntimeException());
    }
}
