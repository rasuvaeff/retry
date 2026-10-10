<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Tests;

use Rasuvaeff\Duration\Duration;
use Rasuvaeff\Retry\BackoffStrategy\ExponentialBackoff;
use Rasuvaeff\Retry\BackoffStrategy\FixedBackoff;
use Rasuvaeff\Retry\BackoffStrategy\ImmediateBackoff;
use Rasuvaeff\Retry\Jitter\AdditiveJitter;
use Rasuvaeff\Retry\Jitter\NoJitter;
use Rasuvaeff\Retry\Randomizer\FixedRandomizer;
use Rasuvaeff\Retry\Randomizer\SystemRandomizer;
use Rasuvaeff\Retry\Retry;
use Rasuvaeff\Retry\RetryPolicy;
use Rasuvaeff\Retry\Sleeper\FakeSleeper;
use Rasuvaeff\Retry\Sleeper\SystemSleeper;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(RetryPolicy::class)]
#[Covers(Retry::class)]
final class RetryPolicyTest
{
    public function fixedPolicyExposesFixedBackoffAndDefaults(): void
    {
        $policy = RetryPolicy::fixed(delayMs: 250, maxAttempts: 4);

        Assert::same($policy->maxAttempts(), 4);
        Assert::instanceOf($policy->backoff(), FixedBackoff::class);
        Assert::same($policy->backoff()->delayMs(attempt: 1), 250);
        Assert::instanceOf($policy->jitter(), NoJitter::class);
        Assert::instanceOf($policy->sleeper(), SystemSleeper::class);
        Assert::instanceOf($policy->randomizer(), SystemRandomizer::class);
    }

    public function exponentialPolicyExposesExponentialBackoff(): void
    {
        $policy = RetryPolicy::exponential(maxAttempts: 5, baseMs: 100, multiplier: 2.0, capMs: 30_000);

        Assert::same($policy->maxAttempts(), 5);
        Assert::instanceOf($policy->backoff(), ExponentialBackoff::class);
        Assert::same($policy->backoff()->delayMs(attempt: 3), 400);
    }

    public function fixedForPolicyBuildsFixedBackoffFromDuration(): void
    {
        $policy = RetryPolicy::fixedFor(delay: Duration::millis(250), maxAttempts: 4);

        Assert::same($policy->maxAttempts(), 4);
        Assert::instanceOf($policy->backoff(), FixedBackoff::class);
        Assert::same($policy->backoff()->delayMs(attempt: 1), 250);
    }

    public function exponentialForPolicyBuildsExponentialBackoffFromDurations(): void
    {
        $policy = RetryPolicy::exponentialFor(base: Duration::millis(100), cap: Duration::seconds(30), multiplier: 2.0, maxAttempts: 5);

        Assert::same($policy->maxAttempts(), 5);
        Assert::instanceOf($policy->backoff(), ExponentialBackoff::class);
        Assert::same($policy->backoff()->delayMs(attempt: 3), 400);
    }

    public function immediatePolicyExposesImmediateBackoff(): void
    {
        $policy = RetryPolicy::immediate(maxAttempts: 2);

        Assert::same($policy->maxAttempts(), 2);
        Assert::instanceOf($policy->backoff(), ImmediateBackoff::class);
        Assert::same($policy->backoff()->delayMs(attempt: 1), 0);
    }

    public function acceptsSingleAttempt(): void
    {
        $policy = RetryPolicy::immediate(maxAttempts: 1);

        Assert::same($policy->maxAttempts(), 1);
    }

    public function rejectsMaxAttemptsBelowOne(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Max attempts');

        RetryPolicy::immediate(maxAttempts: 0);
    }

    public function factoriesDefaultToNoJitter(): void
    {
        Assert::instanceOf(RetryPolicy::fixed()->jitter(), NoJitter::class);
        Assert::instanceOf(RetryPolicy::exponential()->jitter(), NoJitter::class);
        Assert::instanceOf(RetryPolicy::fixedFor(delay: Duration::millis(10))->jitter(), NoJitter::class);
        Assert::instanceOf(
            RetryPolicy::exponentialFor(base: Duration::millis(10), cap: Duration::seconds(1))->jitter(),
            NoJitter::class,
        );
    }

    public function factoriesAcceptJitter(): void
    {
        $jitter = new AdditiveJitter(factor: 0.2);

        Assert::same(RetryPolicy::fixed(jitter: $jitter)->jitter(), $jitter);
        Assert::same(RetryPolicy::exponential(jitter: $jitter)->jitter(), $jitter);
        Assert::same(RetryPolicy::fixedFor(delay: Duration::millis(10), jitter: $jitter)->jitter(), $jitter);
        Assert::same(
            RetryPolicy::exponentialFor(base: Duration::millis(10), cap: Duration::seconds(1), jitter: $jitter)->jitter(),
            $jitter,
        );
    }

    public function retryBuilderConvertsToPolicy(): void
    {
        $sleeper = new FakeSleeper();
        $randomizer = new FixedRandomizer(fraction: 0.5);
        $jitter = new AdditiveJitter(factor: 0.1);
        $policy = Retry::exponential(maxAttempts: 4, baseMs: 100, multiplier: 2.0, capMs: 1_000)
            ->withJitter($jitter)
            ->withSleeper($sleeper)
            ->withRandomizer($randomizer)
            ->toPolicy();

        Assert::same($policy->maxAttempts(), 4);
        Assert::same($policy->backoff()->delayMs(attempt: 3), 400);
        Assert::same($policy->jitter(), $jitter);
        Assert::same($policy->sleeper(), $sleeper);
        Assert::same($policy->randomizer(), $randomizer);
    }
}
