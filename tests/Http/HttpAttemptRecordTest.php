<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Tests\Http;

use Rasuvaeff\Retry\Http\HttpAttemptRecord;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(HttpAttemptRecord::class)]
final class HttpAttemptRecordTest
{
    public function acceptsZeroDelay(): void
    {
        $record = new HttpAttemptRecord(
            attempt: 1,
            delayMs: 0,
            elapsedMs: 0,
            response: new FakeResponse(statusCode: 200),
            exception: null,
        );

        Assert::same($record->delayMs, 0);
    }

    public function rejectsNegativeAttempt(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Attempt must be greater than or equal to 1');

        new HttpAttemptRecord(
            attempt: 0,
            delayMs: 0,
            elapsedMs: 0,
            response: new FakeResponse(statusCode: 200),
            exception: null,
        );
    }

    public function rejectsNegativeDelay(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Delay must be non-negative');

        new HttpAttemptRecord(
            attempt: 1,
            delayMs: -1,
            elapsedMs: 0,
            response: new FakeResponse(statusCode: 200),
            exception: null,
        );
    }

    public function rejectsNegativeElapsed(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Elapsed must be non-negative');

        new HttpAttemptRecord(
            attempt: 1,
            delayMs: 0,
            elapsedMs: -1,
            response: new FakeResponse(statusCode: 200),
            exception: null,
        );
    }

    public function rejectsWhenNeitherResponseNorException(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Either response or exception must be provided');

        new HttpAttemptRecord(attempt: 1, delayMs: 0, elapsedMs: 0, response: null, exception: null);
    }

    public function rejectsWhenBothResponseAndException(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Response and exception cannot be provided together');

        new HttpAttemptRecord(
            attempt: 1,
            delayMs: 0,
            elapsedMs: 0,
            response: new FakeResponse(statusCode: 200),
            exception: new \RuntimeException(),
        );
    }

    public function delayReturnsDurationForNonNullDelay(): void
    {
        $record = new HttpAttemptRecord(
            attempt: 1,
            delayMs: 500,
            elapsedMs: 250,
            response: new FakeResponse(statusCode: 200),
            exception: null,
        );

        Assert::same($record->delay()?->toMillis(), 500);
    }

    public function delayReturnsNullForNullDelay(): void
    {
        $record = new HttpAttemptRecord(
            attempt: 1,
            delayMs: null,
            elapsedMs: 250,
            response: new FakeResponse(statusCode: 200),
            exception: null,
        );

        Assert::null($record->delay());
    }

    public function elapsedReturnsDuration(): void
    {
        $record = new HttpAttemptRecord(
            attempt: 1,
            delayMs: 500,
            elapsedMs: 250,
            response: new FakeResponse(statusCode: 200),
            exception: null,
        );

        Assert::same($record->elapsed()->toMillis(), 250);
    }
}
