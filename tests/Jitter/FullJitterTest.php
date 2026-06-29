<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Tests\Jitter;

use Rasuvaeff\Retry\Jitter\AdditiveJitter;
use Rasuvaeff\Retry\Jitter\FullJitter;
use Rasuvaeff\Retry\Jitter\NoJitter;
use Rasuvaeff\Retry\Randomizer\FixedRandomizer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(FullJitter::class)]
#[Covers(AdditiveJitter::class)]
#[Covers(NoJitter::class)]
#[Covers(FixedRandomizer::class)]
final class FullJitterTest
{
    public function fullJitterReturnsValueInsideDelayRange(): void
    {
        $jitter = new FullJitter();

        $delay = $jitter->apply(
            delayMs: 1_000,
            attempt: 1,
            randomizer: new FixedRandomizer(fraction: 0.5),
        );

        Assert::same($delay, 500);
    }

    public function fullJitterRoundsDown(): void
    {
        $delay = (new FullJitter())->apply(
            delayMs: 10,
            attempt: 1,
            randomizer: new FixedRandomizer(fraction: 0.44),
        );

        Assert::same($delay, 4);
    }

    public function fullJitterRoundsUp(): void
    {
        $delay = (new FullJitter())->apply(
            delayMs: 10,
            attempt: 1,
            randomizer: new FixedRandomizer(fraction: 0.46),
        );

        Assert::same($delay, 5);
    }

    public function additiveJitterReachesLowerBound(): void
    {
        $jitter = new AdditiveJitter(factor: 0.2);

        $delay = $jitter->apply(
            delayMs: 1_000,
            attempt: 1,
            randomizer: new FixedRandomizer(fraction: 1.0),
        );

        Assert::same($delay, 800);
    }

    public function additiveJitterUpperBoundEqualsDelay(): void
    {
        $jitter = new AdditiveJitter(factor: 0.2);

        $delay = $jitter->apply(
            delayMs: 1_000,
            attempt: 1,
            randomizer: new FixedRandomizer(fraction: 0.0),
        );

        Assert::same($delay, 1_000);
    }

    public function additiveJitterNeverExceedsDelay(): void
    {
        $jitter = new AdditiveJitter(factor: 1.0);

        foreach ([0.0, 0.25, 0.5, 0.75, 1.0] as $fraction) {
            $delay = $jitter->apply(
                delayMs: 1_000,
                attempt: 1,
                randomizer: new FixedRandomizer(fraction: $fraction),
            );

            Assert::true($delay <= 1_000);
        }
    }

    public function additiveJitterRoundsDown(): void
    {
        $delay = (new AdditiveJitter(factor: 0.2))->apply(
            delayMs: 10,
            attempt: 1,
            randomizer: new FixedRandomizer(fraction: 0.8),
        );

        Assert::same($delay, 8);
    }

    public function additiveJitterRoundsUp(): void
    {
        $delay = (new AdditiveJitter(factor: 0.2))->apply(
            delayMs: 10,
            attempt: 1,
            randomizer: new FixedRandomizer(fraction: 0.75),
        );

        Assert::same($delay, 9);
    }

    public function additiveJitterAcceptsBoundaryFactors(): void
    {
        $zero = new AdditiveJitter(factor: 0.0);
        $one = new AdditiveJitter(factor: 1.0);

        Assert::same($zero->apply(delayMs: 100, attempt: 1, randomizer: new FixedRandomizer(fraction: 1.0)), 100);
        Assert::same($one->apply(delayMs: 100, attempt: 1, randomizer: new FixedRandomizer(fraction: 1.0)), 0);
    }

    public function additiveJitterRejectsNegativeFactor(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Jitter factor must be between 0 and 1');

        new AdditiveJitter(factor: -0.1);
    }

    public function additiveJitterRejectsFactorAboveOne(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Jitter factor must be between 0 and 1');

        new AdditiveJitter(factor: 1.1);
    }

    public function fixedRandomizerInterpolatesBetweenMinAndMax(): void
    {
        $randomizer = new FixedRandomizer(fraction: 0.5);

        Assert::same($randomizer->float(min: 2.0, max: 6.0), 4.0);
    }

    public function noJitterLeavesDelayUnchanged(): void
    {
        $delay = (new NoJitter())->apply(
            delayMs: 1_234,
            attempt: 3,
            randomizer: new FixedRandomizer(fraction: 0.5),
        );

        Assert::same($delay, 1_234);
    }

    public function fullJitterSequenceIsUniformAcrossRange(): void
    {
        $jitter = new FullJitter();
        $randomizer = new SequenceRandomizer(fractions: [0.0, 0.25, 0.5, 0.75, 1.0]);
        $sum = 0;

        for ($i = 0; $i < 100; $i++) {
            $sum += $jitter->apply(delayMs: 1_000, attempt: 1, randomizer: $randomizer);
        }

        Assert::same((int) ($sum / 100), 500);
    }
}
