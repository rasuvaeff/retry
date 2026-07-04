<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry;

use Rasuvaeff\Duration\Duration;
use Rasuvaeff\Retry\BackoffStrategy\BackoffStrategyInterface;
use Rasuvaeff\Retry\BackoffStrategy\ExponentialBackoff;
use Rasuvaeff\Retry\BackoffStrategy\FixedBackoff;
use Rasuvaeff\Retry\BackoffStrategy\ImmediateBackoff;
use Rasuvaeff\Retry\Jitter\JitterInterface;
use Rasuvaeff\Retry\Jitter\NoJitter;
use Rasuvaeff\Retry\Randomizer\RandomizerInterface;
use Rasuvaeff\Retry\Randomizer\SystemRandomizer;
use Rasuvaeff\Retry\Sleeper\SleeperInterface;
use Rasuvaeff\Retry\Sleeper\SystemSleeper;

/**
 * @api
 */
final readonly class RetryPolicy implements RetryPolicyInterface
{
    public function __construct(
        private int $maxAttempts,
        private BackoffStrategyInterface $backoff,
        private JitterInterface $jitter,
        private SleeperInterface $sleeper,
        private RandomizerInterface $randomizer,
    ) {
        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException('Max attempts must be greater than or equal to 1');
        }
    }

    public static function fixed(int $delayMs = 500, int $maxAttempts = 3): self
    {
        return new self(
            maxAttempts: $maxAttempts,
            backoff: new FixedBackoff(delayMs: $delayMs),
            jitter: new NoJitter(),
            sleeper: new SystemSleeper(),
            randomizer: new SystemRandomizer(),
        );
    }

    public static function exponential(int $maxAttempts = 3, int $baseMs = 100, float $multiplier = 2.0, int $capMs = 30_000): self
    {
        return new self(
            maxAttempts: $maxAttempts,
            backoff: new ExponentialBackoff(baseMs: $baseMs, multiplier: $multiplier, capMs: $capMs),
            jitter: new NoJitter(),
            sleeper: new SystemSleeper(),
            randomizer: new SystemRandomizer(),
        );
    }

    public static function immediate(int $maxAttempts = 3): self
    {
        return new self(
            maxAttempts: $maxAttempts,
            backoff: new ImmediateBackoff(),
            jitter: new NoJitter(),
            sleeper: new SystemSleeper(),
            randomizer: new SystemRandomizer(),
        );
    }

    /**
     * Duration-typed counterpart of {@see self::fixed()}.
     */
    public static function fixedFor(Duration $delay, int $maxAttempts = 3): self
    {
        return self::fixed(delayMs: $delay->toMillis(), maxAttempts: $maxAttempts);
    }

    /**
     * Duration-typed counterpart of {@see self::exponential()}.
     */
    public static function exponentialFor(Duration $base, Duration $cap, float $multiplier = 2.0, int $maxAttempts = 3): self
    {
        return self::exponential(
            maxAttempts: $maxAttempts,
            baseMs: $base->toMillis(),
            multiplier: $multiplier,
            capMs: $cap->toMillis(),
        );
    }

    #[\Override]
    public function maxAttempts(): int
    {
        return $this->maxAttempts;
    }

    #[\Override]
    public function backoff(): BackoffStrategyInterface
    {
        return $this->backoff;
    }

    #[\Override]
    public function jitter(): JitterInterface
    {
        return $this->jitter;
    }

    #[\Override]
    public function sleeper(): SleeperInterface
    {
        return $this->sleeper;
    }

    #[\Override]
    public function randomizer(): RandomizerInterface
    {
        return $this->randomizer;
    }
}
