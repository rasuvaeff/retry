<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Tests\BackoffStrategy;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Retry\BackoffStrategy\ExponentialBackoff;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(ExponentialBackoff::class)]
final class ExponentialBackoffTest
{
    public function firstAttemptUsesBaseDelay(): void
    {
        $backoff = new ExponentialBackoff(baseMs: 100, multiplier: 2.0, capMs: 30_000);

        Assert::same($backoff->delayMs(attempt: 1), 100);
    }

    public function delayGrowsByMultiplier(): void
    {
        $backoff = new ExponentialBackoff(baseMs: 100, multiplier: 2.0, capMs: 30_000);

        Assert::same($backoff->delayMs(attempt: 2), 200);
        Assert::same($backoff->delayMs(attempt: 3), 400);
        Assert::same($backoff->delayMs(attempt: 4), 800);
    }

    public function delayIsCappedAtCapMs(): void
    {
        $backoff = new ExponentialBackoff(baseMs: 100, multiplier: 2.0, capMs: 250);

        Assert::same($backoff->delayMs(attempt: 5), 250);
    }

    public function highAttemptStaysCappedWithoutOverflow(): void
    {
        $backoff = new ExponentialBackoff(baseMs: 100, multiplier: 2.0, capMs: 30_000);

        Assert::same($backoff->delayMs(attempt: 100), 30_000);
        Assert::same($backoff->delayMs(attempt: 1_024), 30_000);
    }

    public function clampsAttemptBelowOneToBaseDelay(): void
    {
        $backoff = new ExponentialBackoff(baseMs: 100, multiplier: 2.0, capMs: 30_000);

        Assert::same($backoff->delayMs(attempt: 0), 100);
    }

    public function acceptsZeroBaseDelay(): void
    {
        $backoff = new ExponentialBackoff(baseMs: 0, multiplier: 2.0, capMs: 30_000);

        Assert::same($backoff->delayMs(attempt: 3), 0);
    }

    public function acceptsMultiplierOfOne(): void
    {
        $backoff = new ExponentialBackoff(baseMs: 100, multiplier: 1.0, capMs: 30_000);

        Assert::same($backoff->delayMs(attempt: 5), 100);
    }

    public function acceptsZeroCap(): void
    {
        $backoff = new ExponentialBackoff(baseMs: 100, multiplier: 2.0, capMs: 0);

        Assert::same($backoff->delayMs(attempt: 3), 0);
    }

    public function roundsHalfUp(): void
    {
        $backoff = new ExponentialBackoff(baseMs: 10, multiplier: 1.5, capMs: 30_000);

        Assert::same($backoff->delayMs(attempt: 4), 34);
    }

    public function roundsNearestBelowHalf(): void
    {
        $backoff = new ExponentialBackoff(baseMs: 10, multiplier: 1.1, capMs: 30_000);

        Assert::same($backoff->delayMs(attempt: 3), 12);
    }

    public function rejectsNegativeBase(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Base delay must be non-negative');

        new ExponentialBackoff(baseMs: -1);
    }

    public function rejectsMultiplierBelowOne(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Multiplier must be greater than or equal to 1');

        new ExponentialBackoff(multiplier: 0.5);
    }

    public function rejectsNegativeCap(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Cap delay must be non-negative');

        new ExponentialBackoff(capMs: -1);
    }

    #[Property(runs: 400, timeoutMs: 1000)]
    public function delayAlwaysWithinZeroAndCap(int $baseMs, float $multiplier, int $capMs, int $attempt): void
    {
        $delay = (new ExponentialBackoff(baseMs: $baseMs, multiplier: $multiplier, capMs: $capMs))
            ->delayMs(attempt: $attempt);

        Assert::true($delay >= 0);
        Assert::true($delay <= $capMs);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function delayAlwaysWithinZeroAndCapGenerators(): array
    {
        return [
            'baseMs' => Gen::intBetween(0, 10_000),
            'multiplier' => Gen::floatBetween(1.0, 4.0),
            'capMs' => Gen::intBetween(0, 60_000),
            'attempt' => Gen::intBetween(1, 40),
        ];
    }

    #[Property(runs: 400, timeoutMs: 1000)]
    public function delayIsNonDecreasingInAttempt(int $baseMs, float $multiplier, int $capMs, int $attempt): void
    {
        $backoff = new ExponentialBackoff(baseMs: $baseMs, multiplier: $multiplier, capMs: $capMs);

        Assert::true($backoff->delayMs(attempt: $attempt) <= $backoff->delayMs(attempt: $attempt + 1));
    }

    /** @return array<string, ArbitraryInterface> */
    public static function delayIsNonDecreasingInAttemptGenerators(): array
    {
        return [
            'baseMs' => Gen::intBetween(0, 10_000),
            'multiplier' => Gen::floatBetween(1.0, 4.0),
            'capMs' => Gen::intBetween(0, 60_000),
            'attempt' => Gen::intBetween(1, 39),
        ];
    }
}
