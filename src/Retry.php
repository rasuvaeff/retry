<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry;

use Psr\Clock\ClockInterface;
use Rasuvaeff\Duration\Duration;
use Rasuvaeff\Retry\BackoffStrategy\BackoffStrategyInterface;
use Rasuvaeff\Retry\BackoffStrategy\ExponentialBackoff;
use Rasuvaeff\Retry\BackoffStrategy\FixedBackoff;
use Rasuvaeff\Retry\BackoffStrategy\ImmediateBackoff;
use Rasuvaeff\Retry\Clock\SystemClock;
use Rasuvaeff\Retry\Jitter\AdditiveJitter;
use Rasuvaeff\Retry\Jitter\FullJitter;
use Rasuvaeff\Retry\Jitter\JitterInterface;
use Rasuvaeff\Retry\Jitter\JitterMode;
use Rasuvaeff\Retry\Jitter\NoJitter;
use Rasuvaeff\Retry\Randomizer\RandomizerInterface;
use Rasuvaeff\Retry\Randomizer\SystemRandomizer;
use Rasuvaeff\Retry\Sleeper\SleeperInterface;
use Rasuvaeff\Retry\Sleeper\SystemSleeper;

/**
 * @api
 */
final readonly class Retry
{
    /**
     * @param list<class-string<\Throwable>> $retryOn
     * @param list<\Closure(\Throwable): bool> $retryIf
     * @param list<\Closure(\Throwable): bool> $stopIf
     * @param list<\Closure(mixed): bool> $retryIfResult
     * @param list<\Closure(AttemptRecord): void> $onRetry
     * @param list<\Closure(RetryExhausted): void> $onExhausted
     */
    private function __construct(
        private int $maxAttempts,
        private BackoffStrategyInterface $backoff,
        private JitterInterface $jitter,
        private SleeperInterface $sleeper,
        private RandomizerInterface $randomizer,
        private ?int $budgetMs,
        private ClockInterface $clock,
        private array $retryOn,
        private array $retryIf,
        private array $stopIf,
        private array $retryIfResult,
        private array $onRetry,
        private array $onExhausted,
    ) {
        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException('Max attempts must be greater than or equal to 1');
        }
    }

    public static function new(): self
    {
        return new self(
            maxAttempts: 3,
            backoff: new ImmediateBackoff(),
            jitter: new NoJitter(),
            sleeper: new SystemSleeper(),
            randomizer: new SystemRandomizer(),
            budgetMs: null,
            clock: new SystemClock(),
            retryOn: [\Exception::class],
            retryIf: [],
            stopIf: [],
            retryIfResult: [],
            onRetry: [],
            onExhausted: [],
        );
    }

    public static function fixed(int $delayMs = 500, int $maxAttempts = 3): self
    {
        return self::new()->withFixed(delayMs: $delayMs, maxAttempts: $maxAttempts);
    }

    public static function exponential(int $maxAttempts = 3, int $baseMs = 100, float $multiplier = 2.0, int $capMs = 30_000): self
    {
        return self::new()->withExponential(
            baseMs: $baseMs,
            multiplier: $multiplier,
            capMs: $capMs,
            maxAttempts: $maxAttempts,
        );
    }

    public static function immediate(int $maxAttempts = 3): self
    {
        return self::new()->withImmediate(maxAttempts: $maxAttempts);
    }

    /**
     * Duration-typed counterpart of {@see self::fixed()}.
     */
    public static function fixedFor(Duration $delay, int $maxAttempts = 3): self
    {
        return self::new()->withFixedFor(delay: $delay, maxAttempts: $maxAttempts);
    }

    /**
     * Duration-typed counterpart of {@see self::exponential()}.
     */
    public static function exponentialFor(Duration $base, Duration $cap, float $multiplier = 2.0, int $maxAttempts = 3): self
    {
        return self::new()->withExponentialFor(
            base: $base,
            cap: $cap,
            multiplier: $multiplier,
            maxAttempts: $maxAttempts,
        );
    }

    public function maxAttempts(int $maxAttempts): self
    {
        return $this->copy(maxAttempts: $maxAttempts);
    }

    public function stopAfterMs(int $budgetMs): self
    {
        if ($budgetMs < 0) {
            throw new \InvalidArgumentException('Budget must be non-negative');
        }

        return $this->copy(budgetMs: $budgetMs);
    }

    /**
     * Duration-typed counterpart of {@see self::stopAfterMs()}.
     */
    public function stopAfter(Duration $budget): self
    {
        return $this->stopAfterMs(budgetMs: $budget->toMillis());
    }

    public function withFixed(int $delayMs = 500, ?int $maxAttempts = null): self
    {
        return $this->copy(
            maxAttempts: $maxAttempts,
            backoff: new FixedBackoff(delayMs: $delayMs),
        );
    }

    public function withExponential(int $baseMs = 100, float $multiplier = 2.0, int $capMs = 30_000, ?int $maxAttempts = null): self
    {
        return $this->copy(
            maxAttempts: $maxAttempts,
            backoff: new ExponentialBackoff(baseMs: $baseMs, multiplier: $multiplier, capMs: $capMs),
        );
    }

    public function withImmediate(?int $maxAttempts = null): self
    {
        return $this->copy(
            maxAttempts: $maxAttempts,
            backoff: new ImmediateBackoff(),
        );
    }

    /**
     * Duration-typed counterpart of {@see self::withFixed()}.
     */
    public function withFixedFor(Duration $delay, ?int $maxAttempts = null): self
    {
        return $this->withFixed(delayMs: $delay->toMillis(), maxAttempts: $maxAttempts);
    }

    /**
     * Duration-typed counterpart of {@see self::withExponential()}.
     */
    public function withExponentialFor(Duration $base, Duration $cap, float $multiplier = 2.0, ?int $maxAttempts = null): self
    {
        return $this->withExponential(
            baseMs: $base->toMillis(),
            multiplier: $multiplier,
            capMs: $cap->toMillis(),
            maxAttempts: $maxAttempts,
        );
    }

    /**
     * Additive jitter uses @param $factor in [0, 1]; the factor is ignored for
     * `JitterMode::Full` and `JitterMode::None`.
     */
    public function jitter(float $factor = 0.2, JitterMode $mode = JitterMode::Additive): self
    {
        $jitter = match ($mode) {
            JitterMode::Additive => new AdditiveJitter(factor: $factor),
            JitterMode::Full => new FullJitter(),
            JitterMode::None => new NoJitter(),
        };

        return $this->withJitter(jitter: $jitter);
    }

    public function withJitter(JitterInterface $jitter): self
    {
        return $this->copy(jitter: $jitter);
    }

    /**
     * @param class-string<\Throwable> ...$classes
     */
    public function retryOn(string ...$classes): self
    {
        /** @var list<class-string<\Throwable>> $classes */
        return $this->copy(retryOn: $classes);
    }

    /**
     * @param \Closure(\Throwable): bool $predicate
     */
    public function retryIf(\Closure $predicate): self
    {
        return $this->copy(retryIf: [...$this->retryIf, $predicate]);
    }

    /**
     * @param \Closure(\Throwable): bool $predicate
     */
    public function stopIf(\Closure $predicate): self
    {
        return $this->copy(stopIf: [...$this->stopIf, $predicate]);
    }

    /**
     * Retries when the operation returns a value the predicate rejects, even
     * though it did not throw. On exhaustion the rejected value is available via
     * `RetryExhausted::lastException` (an {@see UnacceptableResult}).
     *
     * @param \Closure(mixed): bool $predicate true means the result is unacceptable and should be retried
     */
    public function retryIfResult(\Closure $predicate): self
    {
        return $this->copy(retryIfResult: [...$this->retryIfResult, $predicate]);
    }

    /**
     * @param \Closure(AttemptRecord): void $callback
     */
    public function onRetry(\Closure $callback): self
    {
        return $this->copy(onRetry: [...$this->onRetry, $callback]);
    }

    /**
     * @param \Closure(RetryExhausted): void $callback
     */
    public function onExhausted(\Closure $callback): self
    {
        return $this->copy(onExhausted: [...$this->onExhausted, $callback]);
    }

    public function withSleeper(SleeperInterface $sleeper): self
    {
        return $this->copy(sleeper: $sleeper);
    }

    public function withRandomizer(RandomizerInterface $randomizer): self
    {
        return $this->copy(randomizer: $randomizer);
    }

    public function withBackoff(BackoffStrategyInterface $backoff): self
    {
        return $this->copy(backoff: $backoff);
    }

    public function withClock(ClockInterface $clock): self
    {
        return $this->copy(clock: $clock);
    }

    /**
     * Runs the operation, retrying retryable failures.
     *
     * Non-retryable exceptions are rethrown as-is (they bypassed every
     * predicate). A result rejected by `retryIfResult()` is retried as well.
     * Failures that run out of attempts or budget are wrapped in
     * {@see RetryExhausted}; for a rejected result, its `lastException` is an
     * {@see UnacceptableResult} carrying the value.
     *
     * @template T
     *
     * @param \Closure(): T $operation
     *
     * @return T
     *
     * @throws RetryExhausted when attempts or budget are exhausted on a retryable failure
     */
    public function run(\Closure $operation): mixed
    {
        $history = [];
        $startedAt = $this->clock->now();

        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            try {
                $result = $operation();

                foreach ($this->retryIfResult as $predicate) {
                    if ($predicate($result)) {
                        throw new UnacceptableResult(result: $result);
                    }
                }

                return $result;
            } catch (\Throwable $exception) {
                if (!$this->shouldRetry(exception: $exception)) {
                    throw $exception;
                }

                $elapsedMs = $this->elapsedMs(startedAt: $startedAt);
                $isLastAttempt = $attempt >= $this->maxAttempts;

                if ($isLastAttempt) {
                    $history[] = new AttemptRecord(
                        attempt: $attempt,
                        delayMs: null,
                        elapsedMs: $elapsedMs,
                        exception: $exception,
                    );

                    throw $this->exhausted(
                        attempts: $attempt,
                        lastException: $exception,
                        history: $history,
                        reason: ExhaustionReason::MaxAttempts,
                    );
                }

                $delayMs = $this->delayMs(attempt: $attempt);

                if ($this->budgetMs !== null && $elapsedMs + $delayMs > $this->budgetMs) {
                    $history[] = new AttemptRecord(
                        attempt: $attempt,
                        delayMs: null,
                        elapsedMs: $elapsedMs,
                        exception: $exception,
                    );

                    throw $this->exhausted(
                        attempts: $attempt,
                        lastException: $exception,
                        history: $history,
                        reason: ExhaustionReason::TimeBudget,
                    );
                }

                $record = new AttemptRecord(
                    attempt: $attempt,
                    delayMs: $delayMs,
                    elapsedMs: $elapsedMs,
                    exception: $exception,
                );
                $history[] = $record;
                foreach ($this->onRetry as $callback) {
                    $callback($record);
                }

                $this->sleeper->sleepMs(ms: $delayMs);
            }
        }

        throw new \LogicException('Unreachable retry state');
    }

    private function shouldRetry(\Throwable $exception): bool
    {
        if ($exception instanceof UnacceptableResult) {
            return true;
        }

        foreach ($this->stopIf as $predicate) {
            if ($predicate($exception)) {
                return false;
            }
        }

        foreach ($this->retryIf as $predicate) {
            if ($predicate($exception)) {
                return true;
            }
        }

        foreach ($this->retryOn as $class) {
            if ($exception instanceof $class) {
                return true;
            }
        }

        return false;
    }

    private function delayMs(int $attempt): int
    {
        $delayMs = $this->backoff->delayMs(attempt: $attempt);

        return $this->jitter->apply(
            delayMs: $delayMs,
            attempt: $attempt,
            randomizer: $this->randomizer,
        );
    }

    private function elapsedMs(\DateTimeImmutable $startedAt): int
    {
        $now = $this->clock->now();

        // Clamped at zero: a backwards clock step (NTP correction) would
        // otherwise produce a negative elapsed, and AttemptRecord's
        // constructor would throw from inside the retry machinery itself -
        // losing the operation's own exception. Slightly under-counting the
        // budget after a step is the right degradation for a retry primitive.
        return max(
            0,
            ($now->getTimestamp() - $startedAt->getTimestamp()) * 1000
                + (int) (((int) $now->format('u') - (int) $startedAt->format('u')) / 1000),
        );
    }

    /**
     * @param list<AttemptRecord> $history
     */
    private function exhausted(int $attempts, \Throwable $lastException, array $history, ExhaustionReason $reason): RetryExhausted
    {
        $exception = new RetryExhausted(
            attempts: $attempts,
            lastException: $lastException,
            history: $history,
            reason: $reason,
        );
        foreach ($this->onExhausted as $callback) {
            $callback($exception);
        }

        return $exception;
    }

    /**
     * @param list<class-string<\Throwable>>|null $retryOn
     * @param list<\Closure(\Throwable): bool>|null $retryIf
     * @param list<\Closure(\Throwable): bool>|null $stopIf
     * @param list<\Closure(mixed): bool>|null $retryIfResult
     * @param list<\Closure(AttemptRecord): void>|null $onRetry
     * @param list<\Closure(RetryExhausted): void>|null $onExhausted
     */
    private function copy(
        ?int $maxAttempts = null,
        ?BackoffStrategyInterface $backoff = null,
        ?JitterInterface $jitter = null,
        ?SleeperInterface $sleeper = null,
        ?RandomizerInterface $randomizer = null,
        ?int $budgetMs = null,
        ?ClockInterface $clock = null,
        ?array $retryOn = null,
        ?array $retryIf = null,
        ?array $stopIf = null,
        ?array $retryIfResult = null,
        ?array $onRetry = null,
        ?array $onExhausted = null,
    ): self {
        return new self(
            maxAttempts: $maxAttempts ?? $this->maxAttempts,
            backoff: $backoff ?? $this->backoff,
            jitter: $jitter ?? $this->jitter,
            sleeper: $sleeper ?? $this->sleeper,
            randomizer: $randomizer ?? $this->randomizer,
            budgetMs: $budgetMs ?? $this->budgetMs,
            clock: $clock ?? $this->clock,
            retryOn: $retryOn ?? $this->retryOn,
            retryIf: $retryIf ?? $this->retryIf,
            stopIf: $stopIf ?? $this->stopIf,
            retryIfResult: $retryIfResult ?? $this->retryIfResult,
            onRetry: $onRetry ?? $this->onRetry,
            onExhausted: $onExhausted ?? $this->onExhausted,
        );
    }
}
