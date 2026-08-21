<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Tests\Http;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Retry\Clock\FakeClock;
use Rasuvaeff\Retry\Http\RetryAfterParser;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
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

    public function rejectsIntegerWithTrailingNewline(): void
    {
        $parser = new RetryAfterParser(clock: new FakeClock());

        Assert::null($parser->parseMs(headerValue: "120\n"));
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

    /**
     * A 16+-digit delta-seconds used to overflow int on `* 1000` and, under
     * strict_types with a `?int` return, throw TypeError - letting a hostile
     * server crash the caller with one header (it escaped the PSR-18
     * ClientExceptionInterface catch entirely). Any digit string too large to
     * be a usable delay is ignored like other unusable values.
     */
    #[DataProvider('oversizedDeltaSecondsProvider')]
    public function oversizedDeltaSecondsAreIgnoredNotFatal(string $headerValue): void
    {
        $parser = new RetryAfterParser(clock: new FakeClock());

        Assert::null($parser->parseMs(headerValue: $headerValue));
    }

    public static function oversizedDeltaSecondsProvider(): iterable
    {
        yield '19 digits saturates the int cast' => ['99999999999999999999'];
        yield '16 digits overflows on the * 1000' => ['9223372036854776'];
        yield 'exactly PHP_INT_MAX' => [(string) \PHP_INT_MAX];
        yield 'one past the largest usable value' => [(string) (intdiv(\PHP_INT_MAX, 1000) + 1)];
    }

    public function largestUsableDeltaSecondsStillParses(): void
    {
        $parser = new RetryAfterParser(clock: new FakeClock());
        $seconds = intdiv(\PHP_INT_MAX, 1000);

        Assert::same($parser->parseMs(headerValue: (string) $seconds), $seconds * 1000);
    }

    /**
     * createFromFormat's `Y` accepts 1-3 digit years, so a malformed
     * two-digit-year date used to parse as the year 26 AD - far in the past,
     * clamping to an immediate-hammer 0ms delay instead of the documented
     * null-then-backoff fallback.
     */
    public function rejectsTwoDigitYearHttpDate(): void
    {
        $parser = new RetryAfterParser(clock: new FakeClock());

        Assert::null($parser->parseMs(headerValue: 'Thu, 21 Aug 26 10:00:00 GMT'));
    }

    public function rejectsWhitespacePaddedHttpDate(): void
    {
        $parser = new RetryAfterParser(clock: new FakeClock());

        Assert::null($parser->parseMs(headerValue: ' Sun, 01 Jun 2025 12:00:30 GMT'));
    }

    #[Property(runs: 300)]
    public function positiveDeltaSecondsBecomeMilliseconds(int $seconds): void
    {
        $parser = new RetryAfterParser(clock: new FakeClock());

        Assert::same($parser->parseMs(headerValue: (string) $seconds), $seconds * 1000);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function positiveDeltaSecondsBecomeMillisecondsGenerators(): array
    {
        // The full usable range, up to the overflow-guard boundary - the
        // previous cap of 1e6 was five orders of magnitude short of it.
        // Everyday small values are still exercised via edge bias and the
        // Examples below.
        return ['seconds' => Gen::intBetween(1, intdiv(\PHP_INT_MAX, 1000))];
    }

    /** @return iterable<string, array{int}> */
    public static function positiveDeltaSecondsBecomeMillisecondsExamples(): iterable
    {
        yield 'largest usable value' => [intdiv(\PHP_INT_MAX, 1000)];
        yield 'ordinary value' => [120];
    }
}
