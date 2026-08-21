<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Tests;

use Rasuvaeff\Duration\Duration;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Retry\AttemptRecord;
use Rasuvaeff\Retry\BackoffStrategy\ExponentialBackoff;
use Rasuvaeff\Retry\BackoffStrategy\FixedBackoff;
use Rasuvaeff\Retry\Clock\FakeClock;
use Rasuvaeff\Retry\ExhaustionReason;
use Rasuvaeff\Retry\Jitter\JitterMode;
use Rasuvaeff\Retry\Randomizer\FixedRandomizer;
use Rasuvaeff\Retry\Retry;
use Rasuvaeff\Retry\RetryExhausted;
use Rasuvaeff\Retry\Sleeper\FakeSleeper;
use Rasuvaeff\Retry\Tests\Sleeper\ClockAdvancingSleeper;
use Rasuvaeff\Retry\UnacceptableResult;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(Retry::class)]
#[Covers(RetryExhausted::class)]
#[Covers(AttemptRecord::class)]
#[Covers(ExhaustionReason::class)]
#[Covers(UnacceptableResult::class)]
final class RetryTest
{
    public function returnsSuccessfulValueWithoutSleeping(): void
    {
        $sleeper = new FakeSleeper();

        $value = Retry::new()
            ->withSleeper(sleeper: $sleeper)
            ->run(operation: fn(): string => 'ok');

        Assert::same($value, 'ok');
        Assert::same($sleeper->delays(), []);
    }

    public function fixedForBuildsFixedDelayFromDuration(): void
    {
        $sleeper = new FakeSleeper();

        try {
            Retry::fixedFor(delay: Duration::millis(100), maxAttempts: 3)
                ->withSleeper(sleeper: $sleeper)
                ->run(operation: function (): string {
                    throw new \RuntimeException(message: 'down');
                });
        } catch (RetryExhausted) {
            Assert::same($sleeper->delays(), [100, 100]);

            return;
        }

        throw new \RuntimeException(message: 'Expected RetryExhausted');
    }

    public function exponentialForBuildsExponentialDelaysFromDurations(): void
    {
        $sleeper = new FakeSleeper();

        try {
            Retry::exponentialFor(base: Duration::millis(100), cap: Duration::seconds(30), multiplier: 2.0, maxAttempts: 3)
                ->withSleeper(sleeper: $sleeper)
                ->run(operation: function (): string {
                    throw new \RuntimeException(message: 'down');
                });
        } catch (RetryExhausted) {
            Assert::same($sleeper->delays(), [100, 200]);

            return;
        }

        throw new \RuntimeException(message: 'Expected RetryExhausted');
    }

    public function withFixedForBuildsFixedDelayFromDuration(): void
    {
        $sleeper = new FakeSleeper();

        try {
            Retry::new()
                ->withFixedFor(delay: Duration::millis(50))
                ->withSleeper(sleeper: $sleeper)
                ->run(operation: function (): string {
                    throw new \RuntimeException(message: 'down');
                });
        } catch (RetryExhausted) {
            Assert::same($sleeper->delays(), [50, 50]);

            return;
        }

        throw new \RuntimeException(message: 'Expected RetryExhausted');
    }

    public function withExponentialForBuildsExponentialDelaysFromDurations(): void
    {
        $sleeper = new FakeSleeper();

        try {
            Retry::new()
                ->withExponentialFor(base: Duration::millis(100), cap: Duration::seconds(30))
                ->withSleeper(sleeper: $sleeper)
                ->run(operation: function (): string {
                    throw new \RuntimeException(message: 'down');
                });
        } catch (RetryExhausted) {
            Assert::same($sleeper->delays(), [100, 200]);

            return;
        }

        throw new \RuntimeException(message: 'Expected RetryExhausted');
    }

    /**
     * A backwards clock step (NTP correction) between the run's start and a
     * failure must not make the retry machinery itself throw: a negative
     * elapsed would blow up AttemptRecord's constructor from inside the catch
     * block, losing the operation's own exception entirely.
     */
    public function backwardsClockStepDoesNotBreakTheRetryLoop(): void
    {
        $clock = new class implements \Psr\Clock\ClockInterface {
            private int $calls = 0;

            #[\Override]
            public function now(): \DateTimeImmutable
            {
                // Second and later reads are 10s BEFORE the first one.
                return 0 === $this->calls++
                    ? new \DateTimeImmutable('2025-01-01T00:00:10+00:00')
                    : new \DateTimeImmutable('2025-01-01T00:00:00+00:00');
            }
        };
        $records = [];
        $calls = 0;

        $result = Retry::new()
            ->maxAttempts(maxAttempts: 2)
            ->withFixed(delayMs: 1)
            ->withClock(clock: $clock)
            ->withSleeper(sleeper: new FakeSleeper())
            ->onRetry(callback: static function (AttemptRecord $record) use (&$records): void {
                $records[] = $record;
            })
            ->run(operation: function () use (&$calls): string {
                if (1 === ++$calls) {
                    throw new \RuntimeException(message: 'transient');
                }

                return 'ok';
            });

        Assert::same($result, 'ok');
        Assert::same($calls, 2);
        Assert::same($records[0]->elapsedMs, 0);
    }

    public function stopAfterAbortsBeforeNextAttemptWhenBudgetExhausted(): void
    {
        $clock = new FakeClock();
        $sleeper = new ClockAdvancingSleeper(clock: $clock);
        $calls = 0;

        try {
            Retry::new()
                ->maxAttempts(maxAttempts: 5)
                ->withFixed(delayMs: 200)
                ->stopAfter(budget: Duration::millis(300))
                ->withClock(clock: $clock)
                ->withSleeper(sleeper: $sleeper)
                ->run(operation: function () use (&$calls): string {
                    $calls++;

                    throw new \RuntimeException(message: 'down');
                });
        } catch (RetryExhausted $exception) {
            Assert::same($calls, 2);
            Assert::same($exception->reason, ExhaustionReason::TimeBudget);
            Assert::same($sleeper->delays(), [200]);

            return;
        }

        throw new \RuntimeException(message: 'Expected RetryExhausted');
    }

    public function retriesUntilSuccess(): void
    {
        $sleeper = new FakeSleeper();
        $calls = 0;

        $value = Retry::new()
            ->maxAttempts(maxAttempts: 3)
            ->withFixed(delayMs: 100)
            ->withSleeper(sleeper: $sleeper)
            ->run(operation: function () use (&$calls): string {
                $calls++;
                if ($calls < 3) {
                    throw new \RuntimeException(message: 'temporary');
                }

                return 'ok';
            });

        Assert::same($value, 'ok');
        Assert::same($calls, 3);
        Assert::same($sleeper->delays(), [100, 100]);
    }

    public function exponentialBackoffProducesGrowingDelays(): void
    {
        $sleeper = new FakeSleeper();
        $calls = 0;

        $value = Retry::new()
            ->withExponential(baseMs: 50, multiplier: 2.0, capMs: 1_000)
            ->maxAttempts(maxAttempts: 3)
            ->withSleeper(sleeper: $sleeper)
            ->run(operation: function () use (&$calls): string {
                $calls++;
                if ($calls < 3) {
                    throw new \RuntimeException(message: 'temporary');
                }

                return 'ok';
            });

        Assert::same($value, 'ok');
        Assert::same($sleeper->delays(), [50, 100]);
    }

    public function staticExponentialFactoryAppliesMaxAttempts(): void
    {
        $sleeper = new FakeSleeper();
        $calls = 0;

        try {
            Retry::exponential(maxAttempts: 2, baseMs: 10)
                ->withSleeper(sleeper: $sleeper)
                ->run(operation: function () use (&$calls): string {
                    $calls++;

                    throw new \RuntimeException(message: 'down');
                });
        } catch (RetryExhausted $exception) {
            Assert::same($exception->attempts, 2);
            Assert::same($calls, 2);
            Assert::same($sleeper->delays(), [10]);

            return;
        }

        throw new \RuntimeException(message: 'Expected RetryExhausted');
    }

    public function staticImmediateFactoryHasNoDelay(): void
    {
        $sleeper = new FakeSleeper();

        try {
            Retry::immediate(maxAttempts: 2)
                ->withSleeper(sleeper: $sleeper)
                ->run(operation: fn(): string => throw new \RuntimeException(message: 'down'));
        } catch (RetryExhausted $exception) {
            Assert::same($exception->attempts, 2);
            Assert::same($sleeper->delays(), [0]);

            return;
        }

        throw new \RuntimeException(message: 'Expected RetryExhausted');
    }

    public function defaultMaxAttemptsIsThree(): void
    {
        $sleeper = new FakeSleeper();
        $calls = 0;

        try {
            Retry::new()
                ->withSleeper(sleeper: $sleeper)
                ->run(operation: function () use (&$calls): string {
                    $calls++;

                    throw new \RuntimeException(message: 'down');
                });
        } catch (RetryExhausted $exception) {
            Assert::same($calls, 3);
            Assert::same($exception->attempts, 3);

            return;
        }

        throw new \RuntimeException(message: 'Expected RetryExhausted');
    }

    public function singleAttemptRunsExactlyOnceWithoutThrowingAtConstruction(): void
    {
        $sleeper = new FakeSleeper();
        $calls = 0;

        try {
            Retry::new()
                ->maxAttempts(maxAttempts: 1)
                ->withSleeper(sleeper: $sleeper)
                ->run(operation: function () use (&$calls): string {
                    $calls++;

                    throw new \RuntimeException(message: 'down');
                });
        } catch (RetryExhausted $exception) {
            Assert::same($calls, 1);
            Assert::same($exception->attempts, 1);
            Assert::same($sleeper->delays(), []);

            return;
        }

        throw new \RuntimeException(message: 'Expected RetryExhausted');
    }

    public function rejectsMaxAttemptsBelowOne(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Max attempts');

        Retry::new()->maxAttempts(maxAttempts: 0);
    }

    public function staticNamedFactoryBuildsRetry(): void
    {
        $sleeper = new FakeSleeper();
        $calls = 0;

        $value = Retry::fixed(delayMs: 25, maxAttempts: 2)
            ->withSleeper(sleeper: $sleeper)
            ->run(operation: function () use (&$calls): string {
                $calls++;
                if ($calls === 1) {
                    throw new \RuntimeException(message: 'temporary');
                }

                return 'ok';
            });

        Assert::same($value, 'ok');
        Assert::same($sleeper->delays(), [25]);
    }

    public function exhaustedContainsHistoryAndCallsHook(): void
    {
        $sleeper = new FakeSleeper();
        $exhaustedAttempts = 0;

        try {
            Retry::new()
                ->maxAttempts(maxAttempts: 2)
                ->withFixed(delayMs: 10)
                ->withSleeper(sleeper: $sleeper)
                ->onExhausted(callback: function (RetryExhausted $exception) use (&$exhaustedAttempts): void {
                    $exhaustedAttempts = $exception->attempts;
                })
                ->run(operation: fn(): string => throw new \RuntimeException(message: 'down'));
        } catch (RetryExhausted $exception) {
            Assert::same($exception->attempts, 2);
            Assert::same(count($exception->history), 2);
            Assert::same($exception->history[0]->delayMs, 10);
            Assert::null($exception->history[1]->delayMs);
            Assert::same($exhaustedAttempts, 2);

            return;
        }

        throw new \RuntimeException(message: 'Expected RetryExhausted');
    }

    public function stopIfRethrowsOriginalException(): void
    {
        $sleeper = new FakeSleeper();

        try {
            Retry::new()
                ->maxAttempts(maxAttempts: 3)
                ->withSleeper(sleeper: $sleeper)
                ->stopIf(predicate: fn(\Throwable $exception): bool => $exception instanceof \InvalidArgumentException)
                ->run(operation: fn(): string => throw new \InvalidArgumentException(message: 'auth'));
        } catch (\InvalidArgumentException $exception) {
            Assert::same($exception->getMessage(), 'auth');
            Assert::same($sleeper->delays(), []);

            return;
        }

        throw new \RuntimeException(message: 'Expected InvalidArgumentException');
    }

    public function retryIfAllowsPredicateBasedRetries(): void
    {
        $sleeper = new FakeSleeper();
        $calls = 0;

        $value = Retry::new()
            ->retryOn()
            ->retryIf(predicate: fn(\Throwable $exception): bool => $exception->getCode() === 503)
            ->maxAttempts(maxAttempts: 2)
            ->withSleeper(sleeper: $sleeper)
            ->run(operation: function () use (&$calls): string {
                $calls++;
                if ($calls === 1) {
                    throw new \RuntimeException(message: 'unavailable', code: 503);
                }

                return 'ok';
            });

        Assert::same($value, 'ok');
        Assert::same($calls, 2);
    }

    public function retryOnRetriesListedClass(): void
    {
        $sleeper = new FakeSleeper();
        $calls = 0;

        $value = Retry::new()
            ->maxAttempts(maxAttempts: 3)
            ->retryOn(\RuntimeException::class)
            ->withSleeper(sleeper: $sleeper)
            ->run(operation: function () use (&$calls): string {
                $calls++;
                if ($calls < 3) {
                    throw new \RuntimeException(message: 'temp');
                }

                return 'ok';
            });

        Assert::same($value, 'ok');
        Assert::same($calls, 3);
    }

    public function retryOnDoesNotRetryUnlistedClass(): void
    {
        $sleeper = new FakeSleeper();
        $calls = 0;

        try {
            Retry::new()
                ->maxAttempts(maxAttempts: 3)
                ->retryOn(\RuntimeException::class)
                ->withSleeper(sleeper: $sleeper)
                ->run(operation: function () use (&$calls): string {
                    $calls++;

                    throw new \LogicException(message: 'not retryable');
                });
        } catch (\LogicException $exception) {
            Assert::same($calls, 1);
            Assert::same($exception->getMessage(), 'not retryable');
            Assert::same($sleeper->delays(), []);

            return;
        }

        throw new \RuntimeException(message: 'Expected LogicException');
    }

    public function invokesEveryOnRetryCallbackWithAttemptRecord(): void
    {
        $sleeper = new FakeSleeper();
        $first = [];
        $second = [];
        $calls = 0;

        Retry::new()
            ->maxAttempts(maxAttempts: 3)
            ->withFixed(delayMs: 5)
            ->withSleeper(sleeper: $sleeper)
            ->onRetry(callback: function (AttemptRecord $record) use (&$first): void {
                $first[] = [$record->attempt, $record->delayMs, $record->elapsedMs];
            })
            ->onRetry(callback: function (AttemptRecord $record) use (&$second): void {
                $second[] = [$record->attempt, $record->delayMs, $record->elapsedMs];
            })
            ->run(operation: function () use (&$calls): string {
                $calls++;
                if ($calls < 3) {
                    throw new \RuntimeException(message: 'temp');
                }

                return 'ok';
            });

        Assert::same($first, [[1, 5, 0], [2, 5, 0]]);
        Assert::same($second, [[1, 5, 0], [2, 5, 0]]);
    }

    public function stopAfterMsAbortsBeforeNextAttemptWhenBudgetExhausted(): void
    {
        $clock = new FakeClock();
        $sleeper = new ClockAdvancingSleeper(clock: $clock);
        $calls = 0;

        try {
            Retry::new()
                ->maxAttempts(maxAttempts: 5)
                ->withFixed(delayMs: 200)
                ->stopAfterMs(budgetMs: 300)
                ->withClock(clock: $clock)
                ->withSleeper(sleeper: $sleeper)
                ->run(operation: function () use (&$calls): string {
                    $calls++;

                    throw new \RuntimeException(message: 'down');
                });
        } catch (RetryExhausted $exception) {
            Assert::same($calls, 2);
            Assert::same($exception->attempts, 2);
            Assert::same($sleeper->delays(), [200]);

            return;
        }

        throw new \RuntimeException(message: 'Expected RetryExhausted');
    }

    public function stopAfterMsAllowsAllAttemptsWhenBudgetLargeEnough(): void
    {
        $clock = new FakeClock();
        $sleeper = new ClockAdvancingSleeper(clock: $clock);
        $calls = 0;

        $value = Retry::new()
            ->maxAttempts(maxAttempts: 3)
            ->withFixed(delayMs: 100)
            ->stopAfterMs(budgetMs: 10_000)
            ->withClock(clock: $clock)
            ->withSleeper(sleeper: $sleeper)
            ->run(operation: function () use (&$calls): string {
                $calls++;
                if ($calls < 3) {
                    throw new \RuntimeException(message: 'temp');
                }

                return 'ok';
            });

        Assert::same($value, 'ok');
        Assert::same($calls, 3);
    }

    public function stopAfterMsStillAllowsAtLeastOneAttempt(): void
    {
        $clock = new FakeClock();
        $sleeper = new ClockAdvancingSleeper(clock: $clock);
        $calls = 0;

        try {
            Retry::new()
                ->maxAttempts(maxAttempts: 5)
                ->withFixed(delayMs: 100)
                ->stopAfterMs(budgetMs: 0)
                ->withClock(clock: $clock)
                ->withSleeper(sleeper: $sleeper)
                ->run(operation: function () use (&$calls): string {
                    $calls++;

                    throw new \RuntimeException(message: 'down');
                });
        } catch (RetryExhausted $exception) {
            Assert::same($calls, 1);
            Assert::same($exception->attempts, 1);
            Assert::same($sleeper->delays(), []);

            return;
        }

        throw new \RuntimeException(message: 'Expected RetryExhausted');
    }

    public function rejectsNegativeBudget(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Budget must be non-negative');

        Retry::new()->stopAfterMs(budgetMs: -1);
    }

    public function historyRecordCarriesElapsedMs(): void
    {
        $clock = new FakeClock();
        $sleeper = new ClockAdvancingSleeper(clock: $clock);
        $calls = 0;

        try {
            Retry::new()
                ->maxAttempts(maxAttempts: 3)
                ->withFixed(delayMs: 100)
                ->withClock(clock: $clock)
                ->withSleeper(sleeper: $sleeper)
                ->run(operation: function () use (&$calls): string {
                    $calls++;

                    throw new \RuntimeException(message: 'down');
                });
        } catch (RetryExhausted $exception) {
            Assert::same(count($exception->history), 3);
            Assert::same($exception->history[0]->elapsedMs, 0);
            Assert::same($exception->history[1]->elapsedMs, 100);
            Assert::same($exception->history[2]->elapsedMs, 200);

            return;
        }

        throw new \RuntimeException(message: 'Expected RetryExhausted');
    }

    public function maxAttemptsExhaustionReportsCorrectReason(): void
    {
        $sleeper = new FakeSleeper();

        try {
            Retry::immediate(maxAttempts: 2)
                ->withSleeper(sleeper: $sleeper)
                ->run(operation: fn(): string => throw new \RuntimeException(message: 'down'));
        } catch (RetryExhausted $exception) {
            Assert::same($exception->reason, ExhaustionReason::MaxAttempts);
            Assert::string($exception->getMessage())->contains('Retry exhausted after 2 attempt(s)');

            return;
        }

        throw new \RuntimeException(message: 'Expected RetryExhausted');
    }

    public function timeBudgetExhaustionReportsCorrectReason(): void
    {
        $clock = new FakeClock();
        $sleeper = new ClockAdvancingSleeper(clock: $clock);

        try {
            Retry::new()
                ->maxAttempts(maxAttempts: 5)
                ->withFixed(delayMs: 200)
                ->stopAfterMs(budgetMs: 300)
                ->withClock(clock: $clock)
                ->withSleeper(sleeper: $sleeper)
                ->run(operation: fn(): string => throw new \RuntimeException(message: 'down'));
        } catch (RetryExhausted $exception) {
            Assert::same($exception->reason, ExhaustionReason::TimeBudget);
            Assert::string($exception->getMessage())->contains('Retry budget exceeded after 2 attempt(s)');
            Assert::null($exception->history[count($exception->history) - 1]->delayMs);

            return;
        }

        throw new \RuntimeException(message: 'Expected RetryExhausted');
    }

    public function lastAttemptRecordHasNullDelayMs(): void
    {
        $sleeper = new FakeSleeper();

        try {
            Retry::immediate(maxAttempts: 3)
                ->withSleeper(sleeper: $sleeper)
                ->run(operation: fn(): string => throw new \RuntimeException(message: 'down'));
        } catch (RetryExhausted $exception) {
            $last = $exception->history[count($exception->history) - 1];
            Assert::null($last->delayMs);
            Assert::same($exception->history[0]->delayMs, 0);
            Assert::same($exception->history[1]->delayMs, 0);

            return;
        }

        throw new \RuntimeException(message: 'Expected RetryExhausted');
    }

    public function invokesEveryOnExhaustedCallback(): void
    {
        $sleeper = new FakeSleeper();
        $first = 0;
        $second = 0;

        try {
            Retry::new()
                ->maxAttempts(maxAttempts: 1)
                ->withSleeper(sleeper: $sleeper)
                ->onExhausted(callback: function (RetryExhausted $exception) use (&$first): void {
                    $first = $exception->attempts;
                })
                ->onExhausted(callback: function (RetryExhausted $exception) use (&$second): void {
                    $second = $exception->attempts;
                })
                ->run(operation: fn(): string => throw new \RuntimeException(message: 'down'));
        } catch (RetryExhausted) {
            Assert::same($first, 1);
            Assert::same($second, 1);

            return;
        }

        throw new \RuntimeException(message: 'Expected RetryExhausted');
    }

    public function keepsEveryStopIfPredicate(): void
    {
        $sleeper = new FakeSleeper();
        $calls = 0;

        try {
            Retry::new()
                ->maxAttempts(maxAttempts: 3)
                ->withSleeper(sleeper: $sleeper)
                ->stopIf(predicate: fn(\Throwable $exception): bool => $exception instanceof \RuntimeException)
                ->stopIf(predicate: fn(\Throwable $exception): bool => $exception instanceof \LogicException)
                ->run(operation: function () use (&$calls): string {
                    $calls++;

                    throw new \RuntimeException(message: 'first predicate matches');
                });
        } catch (\RuntimeException $exception) {
            Assert::same($calls, 1);
            Assert::same($exception->getMessage(), 'first predicate matches');

            return;
        }

        throw new \RuntimeException(message: 'Expected RuntimeException');
    }

    public function keepsEveryRetryIfPredicate(): void
    {
        $sleeper = new FakeSleeper();
        $calls = 0;

        $value = Retry::new()
            ->retryOn()
            ->retryIf(predicate: fn(\Throwable $exception): bool => $exception->getCode() === 500)
            ->retryIf(predicate: fn(\Throwable $exception): bool => $exception->getCode() === 503)
            ->maxAttempts(maxAttempts: 2)
            ->withSleeper(sleeper: $sleeper)
            ->run(operation: function () use (&$calls): string {
                $calls++;
                if ($calls === 1) {
                    throw new \RuntimeException(message: 'first predicate matches', code: 500);
                }

                return 'ok';
            });

        Assert::same($value, 'ok');
        Assert::same($calls, 2);
    }

    public function builderAppliesConfiguredJitter(): void
    {
        $sleeper = new FakeSleeper();
        $calls = 0;

        Retry::new()
            ->maxAttempts(maxAttempts: 3)
            ->withFixed(delayMs: 1_000)
            ->jitter(factor: 0.2)
            ->withRandomizer(randomizer: new FixedRandomizer(fraction: 1.0))
            ->withSleeper(sleeper: $sleeper)
            ->run(operation: function () use (&$calls): string {
                $calls++;
                if ($calls < 3) {
                    throw new \RuntimeException(message: 'temp');
                }

                return 'ok';
            });

        Assert::same($sleeper->delays(), [800, 800]);
    }

    public function appliesFullJitterMode(): void
    {
        $sleeper = new FakeSleeper();
        $calls = 0;

        Retry::new()
            ->maxAttempts(maxAttempts: 3)
            ->withFixed(delayMs: 1_000)
            ->jitter(factor: 0.5, mode: JitterMode::Full)
            ->withRandomizer(randomizer: new FixedRandomizer(fraction: 0.0))
            ->withSleeper(sleeper: $sleeper)
            ->run(operation: function () use (&$calls): string {
                $calls++;
                if ($calls < 3) {
                    throw new \RuntimeException(message: 'temp');
                }

                return 'ok';
            });

        Assert::same($sleeper->delays(), [0, 0]);
    }

    public function appliesNoneJitterMode(): void
    {
        $sleeper = new FakeSleeper();
        $calls = 0;

        Retry::new()
            ->maxAttempts(maxAttempts: 3)
            ->withFixed(delayMs: 1_000)
            ->jitter(factor: 0.5, mode: JitterMode::None)
            ->withSleeper(sleeper: $sleeper)
            ->run(operation: function () use (&$calls): string {
                $calls++;
                if ($calls < 3) {
                    throw new \RuntimeException(message: 'temp');
                }

                return 'ok';
            });

        Assert::same($sleeper->delays(), [1_000, 1_000]);
    }

    public function fullJitterModeIgnoresFactor(): void
    {
        $sleeper = new FakeSleeper();
        $calls = 0;

        Retry::new()
            ->maxAttempts(maxAttempts: 3)
            ->withFixed(delayMs: 1_000)
            ->jitter(factor: 1.0, mode: JitterMode::Full)
            ->withRandomizer(randomizer: new FixedRandomizer(fraction: 0.5))
            ->withSleeper(sleeper: $sleeper)
            ->run(operation: function () use (&$calls): string {
                $calls++;
                if ($calls < 3) {
                    throw new \RuntimeException(message: 'temp');
                }

                return 'ok';
            });

        Assert::same($sleeper->delays(), [500, 500]);
    }

    public function retryIfResultRetriesRejectedValue(): void
    {
        $sleeper = new FakeSleeper();
        $calls = 0;

        $value = Retry::new()
            ->maxAttempts(maxAttempts: 3)
            ->withFixed(delayMs: 10)
            ->retryIfResult(predicate: fn(mixed $result): bool => $result === 'bad')
            ->withSleeper(sleeper: $sleeper)
            ->run(operation: function () use (&$calls): string {
                $calls++;

                return $calls < 3 ? 'bad' : 'good';
            });

        Assert::same($value, 'good');
        Assert::same($calls, 3);
        Assert::same($sleeper->delays(), [10, 10]);
    }

    public function acceptableResultIsReturnedWithoutRetry(): void
    {
        $sleeper = new FakeSleeper();

        $value = Retry::new()
            ->retryIfResult(predicate: fn(mixed $result): bool => $result === 'bad')
            ->withSleeper(sleeper: $sleeper)
            ->run(operation: fn(): string => 'good');

        Assert::same($value, 'good');
        Assert::same($sleeper->delays(), []);
    }

    public function exhaustedResultExposesUnacceptableResult(): void
    {
        try {
            Retry::new()
                ->maxAttempts(maxAttempts: 2)
                ->withSleeper(sleeper: new FakeSleeper())
                ->retryIfResult(predicate: fn(mixed $result): bool => $result === 'bad')
                ->run(operation: fn(): string => 'bad');
        } catch (RetryExhausted $exception) {
            Assert::instanceOf($exception->lastException, UnacceptableResult::class);
            Assert::same($exception->lastException->result, 'bad');
            Assert::same($exception->lastException->getMessage(), 'Operation returned an unacceptable result');
            Assert::same($exception->reason, ExhaustionReason::MaxAttempts);

            return;
        }

        throw new \RuntimeException(message: 'Expected RetryExhausted');
    }

    public function doesNotRetryErrorsByDefault(): void
    {
        $calls = 0;

        try {
            Retry::new()
                ->withSleeper(sleeper: new FakeSleeper())
                ->run(operation: function () use (&$calls): string {
                    $calls++;

                    throw new \TypeError(message: 'bug');
                });
        } catch (\TypeError $error) {
            Assert::same($error->getMessage(), 'bug');
            Assert::same($calls, 1);

            return;
        }

        throw new \RuntimeException(message: 'Expected TypeError');
    }

    public function retriesErrorsWhenOptedIn(): void
    {
        $calls = 0;

        $value = Retry::new()
            ->maxAttempts(maxAttempts: 3)
            ->withSleeper(sleeper: new FakeSleeper())
            ->retryOn(\Error::class)
            ->run(operation: function () use (&$calls): string {
                $calls++;
                if ($calls < 3) {
                    throw new \TypeError(message: 'bug');
                }

                return 'ok';
            });

        Assert::same($value, 'ok');
        Assert::same($calls, 3);
    }

    public function retryIfResultKeepsEveryPredicate(): void
    {
        $calls = 0;

        $value = Retry::new()
            ->maxAttempts(maxAttempts: 3)
            ->withSleeper(sleeper: new FakeSleeper())
            ->retryIfResult(predicate: fn(mixed $result): bool => $result === 'a')
            ->retryIfResult(predicate: fn(mixed $result): bool => $result === 'b')
            ->run(operation: function () use (&$calls): string {
                $calls++;

                return $calls === 1 ? 'a' : 'ok';
            });

        Assert::same($value, 'ok');
        Assert::same($calls, 2);
    }

    public function resultRetryIsIndependentOfRetryOnAllowList(): void
    {
        try {
            Retry::new()
                ->maxAttempts(maxAttempts: 2)
                ->withSleeper(sleeper: new FakeSleeper())
                ->retryOn(\LogicException::class)
                ->retryIfResult(predicate: fn(mixed $result): bool => $result === 'bad')
                ->run(operation: fn(): string => 'bad');
        } catch (RetryExhausted $exception) {
            Assert::instanceOf($exception->lastException, UnacceptableResult::class);

            return;
        }

        throw new \RuntimeException(message: 'Expected RetryExhausted');
    }

    public function lastStopAfterMsWins(): void
    {
        $calls = 0;

        try {
            Retry::new()
                ->maxAttempts(maxAttempts: 5)
                ->withFixed(delayMs: 100)
                ->stopAfterMs(budgetMs: 10_000)
                ->stopAfterMs(budgetMs: 0)
                ->withSleeper(sleeper: new FakeSleeper())
                ->run(operation: function () use (&$calls): never {
                    $calls++;

                    throw new \RuntimeException(message: 'fail');
                });
        } catch (RetryExhausted $exception) {
            Assert::same($exception->reason, ExhaustionReason::TimeBudget);
            Assert::same($calls, 1);

            return;
        }

        throw new \RuntimeException(message: 'Expected RetryExhausted');
    }

    public function budgetExactlyEqualToNextDelayStillRetries(): void
    {
        $clock = new FakeClock();
        $sleeper = new ClockAdvancingSleeper(clock: $clock);
        $calls = 0;

        try {
            Retry::new()
                ->maxAttempts(maxAttempts: 5)
                ->withFixed(delayMs: 100)
                ->stopAfterMs(budgetMs: 100)
                ->withSleeper(sleeper: $sleeper)
                ->withClock(clock: $clock)
                ->run(operation: function () use (&$calls): never {
                    $calls++;

                    throw new \RuntimeException(message: 'fail');
                });
        } catch (RetryExhausted) {
            Assert::same($calls, 2);

            return;
        }

        throw new \RuntimeException(message: 'Expected RetryExhausted');
    }

    public function elapsedMsAccountsForFullSecondsAndMillis(): void
    {
        $clock = new FakeClock(now: new \DateTimeImmutable('2025-01-01T00:00:00.250000+00:00'));
        $sleeper = new ClockAdvancingSleeper(clock: $clock);

        try {
            Retry::new()
                ->maxAttempts(maxAttempts: 2)
                ->withFixed(delayMs: 1_999)
                ->withSleeper(sleeper: $sleeper)
                ->withClock(clock: $clock)
                ->run(operation: function (): never {
                    throw new \RuntimeException(message: 'fail');
                });
        } catch (RetryExhausted $exception) {
            Assert::same($exception->history[1]->elapsedMs, 1_999);

            return;
        }

        throw new \RuntimeException(message: 'Expected RetryExhausted');
    }

    public function elapsedMsKeepsSubSecondMillisecondPrecision(): void
    {
        $clock = new FakeClock(now: new \DateTimeImmutable('2025-01-01T00:00:00.000000+00:00'));
        $sleeper = new ClockAdvancingSleeper(clock: $clock);

        try {
            Retry::new()
                ->maxAttempts(maxAttempts: 2)
                ->withFixed(delayMs: 999)
                ->withSleeper(sleeper: $sleeper)
                ->withClock(clock: $clock)
                ->run(operation: function (): never {
                    throw new \RuntimeException(message: 'fail');
                });
        } catch (RetryExhausted $exception) {
            Assert::same($exception->history[1]->elapsedMs, 999);

            return;
        }

        throw new \RuntimeException(message: 'Expected RetryExhausted');
    }

    #[Property(runs: 200, timeoutMs: 1000)]
    public function callCountNeverExceedsMaxAttempts(int $maxAttempts, int $failUntil): void
    {
        $calls = 0;

        try {
            Retry::immediate(maxAttempts: $maxAttempts)
                ->withSleeper(sleeper: new FakeSleeper())
                ->run(operation: function () use (&$calls, $failUntil, $maxAttempts): string {
                    $calls++;

                    // \Error is outside the default retryOn list, so it escapes
                    // the loop instead of being retried: timeoutMs cannot
                    // interrupt a body that never returns, and a retry loop that
                    // stopped counting attempts would otherwise spin forever.
                    if ($calls > $maxAttempts) {
                        throw new \Error(message: 'Operation called past maxAttempts');
                    }

                    if ($calls <= $failUntil) {
                        throw new \RuntimeException(message: 'fail');
                    }

                    return 'ok';
                });
        } catch (RetryExhausted) {
            // Expected when the operation keeps failing past maxAttempts.
        }

        // maxAttempts is 1-10 and failUntil 0-15, so exhaustion is about
        // two thirds of draws and success about a third; both floors sit
        // under half their share.
        Classify::cover($failUntil >= $maxAttempts, 'exhausted every attempt', 25.0);
        Classify::cover($failUntil < $maxAttempts, 'succeeded before the cap', 15.0);

        Assert::true($calls <= $maxAttempts);
    }

    /** @return iterable<string, array{int, int}> */
    public static function callCountNeverExceedsMaxAttemptsExamples(): iterable
    {
        yield 'a single attempt that succeeds' => [1, 0];
        yield 'a single attempt that fails' => [1, 1];
        yield 'succeeds on the last allowed attempt' => [3, 2];
        yield 'fails on the last allowed attempt' => [3, 3];
    }

    #[Property(runs: 300)]
    public function exponentialDelayNeverLeavesItsBoundsAndNeverDecreases(
        int $baseMs,
        int $capMs,
        int $attempt,
        int $step,
    ): void {
        $backoff = new ExponentialBackoff(baseMs: $baseMs, multiplier: 2.0, capMs: $capMs);

        $delay = $backoff->delayMs($attempt);
        $later = $backoff->delayMs($attempt + $step);

        // The cap is the reason a caller can bound a retry budget at all, and
        // the monotonicity is what makes exponential backoff back off.
        Classify::cover($delay >= $capMs, 'already at the cap', 20.0);
        Classify::cover($delay < $capMs, 'still climbing', 20.0);
        Classify::when($step === 0, 'the same attempt twice');

        Assert::true($delay >= 0 && $delay <= $capMs);
        Assert::true($later >= $delay);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function exponentialDelayNeverLeavesItsBoundsAndNeverDecreasesGenerators(): array
    {
        // Attempts reach far enough past the cap that both regimes occur: with
        // a base of up to 10s doubling each time, everything beyond attempt 12
        // is capped whatever the base.
        return [
            'baseMs' => Gen::intBetween(0, 10_000),
            'capMs' => Gen::intBetween(0, 60_000),
            'attempt' => Gen::intBetween(1, 20),
            'step' => Gen::intBetween(0, 5),
        ];
    }

    /** @return iterable<string, array{int, int, int, int}> */
    public static function exponentialDelayNeverLeavesItsBoundsAndNeverDecreasesExamples(): iterable
    {
        // A zero base and a zero cap are both legal and both make every delay
        // zero — the degenerate cases a bounds check most often forgets.
        yield 'zero base' => [0, 30_000, 1, 1];
        yield 'zero cap' => [100, 0, 1, 1];
        yield 'the first attempt is the base' => [100, 30_000, 1, 0];
        yield 'far past the cap' => [100, 1_000, 20, 5];
    }

    #[Property(runs: 200)]
    public function aFixedBackoffIsTheSameDelayAtEveryAttempt(int $delayMs, int $attempt, int $other): void
    {
        $backoff = new FixedBackoff(delayMs: $delayMs);

        Classify::when($attempt === $other, 'the same attempt twice');

        // "Fixed" is a contract, not an implementation detail: a caller sizing
        // a retry budget multiplies this by maxAttempts.
        Assert::same($backoff->delayMs($attempt), $delayMs);
        Assert::same($backoff->delayMs($other), $backoff->delayMs($attempt));
    }

    /** @return array<string, ArbitraryInterface> */
    public static function aFixedBackoffIsTheSameDelayAtEveryAttemptGenerators(): array
    {
        return [
            'delayMs' => Gen::intBetween(0, 60_000),
            'attempt' => Gen::intBetween(1, 50),
            'other' => Gen::intBetween(1, 50),
        ];
    }

    /** @return array<string, ArbitraryInterface> */
    public static function callCountNeverExceedsMaxAttemptsGenerators(): array
    {
        return [
            'maxAttempts' => Gen::intBetween(1, 10),
            'failUntil' => Gen::intBetween(0, 15),
        ];
    }

    #[Property(runs: 200, timeoutMs: 1000)]
    public function callCountEqualsSucceedingAttempt(int $succeedOn, int $slack): void
    {
        $maxAttempts = $succeedOn + $slack;
        $calls = 0;

        Retry::immediate(maxAttempts: $maxAttempts)
            ->withSleeper(sleeper: new FakeSleeper())
            ->run(operation: function () use (&$calls, $succeedOn, $maxAttempts): string {
                $calls++;

                if ($calls > $maxAttempts) {
                    throw new \Error(message: 'Operation called past maxAttempts');
                }

                if ($calls < $succeedOn) {
                    throw new \RuntimeException(message: 'fail');
                }

                return 'ok';
            });

        Assert::same($calls, $succeedOn);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function callCountEqualsSucceedingAttemptGenerators(): array
    {
        return [
            'succeedOn' => Gen::intBetween(1, 8),
            'slack' => Gen::intBetween(0, 5),
        ];
    }
}
