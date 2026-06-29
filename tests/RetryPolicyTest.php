<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Tests;

use Rasuvaeff\Retry\BackoffStrategy\ExponentialBackoff;
use Rasuvaeff\Retry\BackoffStrategy\FixedBackoff;
use Rasuvaeff\Retry\BackoffStrategy\ImmediateBackoff;
use Rasuvaeff\Retry\Jitter\NoJitter;
use Rasuvaeff\Retry\Randomizer\SystemRandomizer;
use Rasuvaeff\Retry\RetryPolicy;
use Rasuvaeff\Retry\Sleeper\SystemSleeper;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(RetryPolicy::class)]
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
}
