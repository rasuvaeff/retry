<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Tests\Http;

use Rasuvaeff\Retry\Clock\FakeClock;
use Rasuvaeff\Retry\Http\RetryAfterParser;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(RetryAfterParser::class)]
final class RetryAfterParserTest
{
    public function returnsNullForAbsentHeader(): void
    {
        $parser = new RetryAfterParser(clock: new FakeClock());

        Assert::null($parser->parseMs(headerValue: null));
    }

    public function returnsNullForEmptyHeader(): void
    {
        $parser = new RetryAfterParser(clock: new FakeClock());

        Assert::null($parser->parseMs(headerValue: ''));
    }

    public function parsesIntegerSecondsAsMilliseconds(): void
    {
        $parser = new RetryAfterParser(clock: new FakeClock());

        Assert::same($parser->parseMs(headerValue: '120'), 120_000);
    }

    public function ignoresZeroDeltaSeconds(): void
    {
        $parser = new RetryAfterParser(clock: new FakeClock());

        Assert::null($parser->parseMs(headerValue: '0'));
    }

    public function parsesHttpDateInTheFuture(): void
    {
        $now = new \DateTimeImmutable('2025-06-01T12:00:00+00:00');
        $parser = new RetryAfterParser(clock: new FakeClock(now: $now));

        $delay = $parser->parseMs(headerValue: 'Sun, 01 Jun 2025 12:00:30 GMT');

        Assert::same($delay, 30_000);
    }

    public function clampsHttpDateInThePastToZero(): void
    {
        $now = new \DateTimeImmutable('2025-06-01T12:00:30+00:00');
        $parser = new RetryAfterParser(clock: new FakeClock(now: $now));

        $delay = $parser->parseMs(headerValue: 'Sun, 01 Jun 2025 12:00:00 GMT');

        Assert::same($delay, 0);
    }

    public function returnsNullForUnparseableValue(): void
    {
        $parser = new RetryAfterParser(clock: new FakeClock());

        Assert::null($parser->parseMs(headerValue: 'not-a-date'));
    }

    public function ignoresValueWithDigitsButNotPureNumeric(): void
    {
        $parser = new RetryAfterParser(clock: new FakeClock());

        // Anchored /^\d+$/ must reject digits surrounded by non-digits; without
        // the ^ or $ anchor this would parse as 5 seconds.
        Assert::null($parser->parseMs(headerValue: '5x5'));
    }

    public function httpDateAppliesSubSecondMillisecondCorrection(): void
    {
        $clock = new FakeClock(now: new \DateTimeImmutable('2025-06-01T12:00:00.999000+00:00'));
        $parser = new RetryAfterParser(clock: $clock);

        // 5s ahead, minus the 999ms already elapsed within the current second.
        Assert::same($parser->parseMs(headerValue: 'Sun, 01 Jun 2025 12:00:05 GMT'), 4_001);
    }

    public function returnsNullForLegacyAsctimeFormat(): void
    {
        $parser = new RetryAfterParser(clock: new FakeClock());

        Assert::null($parser->parseMs(headerValue: 'Jun  1 2025 12:00:00'));
    }

    public function preservesMicrosecondPrecision(): void
    {
        $now = new \DateTimeImmutable('2025-06-01T12:00:00.500000+00:00');
        $parser = new RetryAfterParser(clock: new FakeClock(now: $now));

        $delay = $parser->parseMs(headerValue: 'Sun, 01 Jun 2025 12:00:01 GMT');

        Assert::same($delay, 500);
    }
}
