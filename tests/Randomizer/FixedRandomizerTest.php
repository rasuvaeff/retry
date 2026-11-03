<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Tests\Randomizer;

use Rasuvaeff\Retry\Randomizer\FixedRandomizer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(FixedRandomizer::class)]
final class FixedRandomizerTest
{
    public function interpolatesBetweenMinAndMaxByTheFraction(): void
    {
        Assert::same((new FixedRandomizer(fraction: 0.25))->float(min: 2.0, max: 6.0), 3.0);
    }

    public function theBoundaryFractionsPickTheEndsOfTheRange(): void
    {
        Assert::same((new FixedRandomizer(fraction: 0.0))->float(min: 2.0, max: 6.0), 2.0);
        Assert::same((new FixedRandomizer(fraction: 1.0))->float(min: 2.0, max: 6.0), 6.0);
    }

    public function aDegenerateRangeIsAccepted(): void
    {
        Assert::same((new FixedRandomizer(fraction: 0.5))->float(min: 4.0, max: 4.0), 4.0);
    }

    #[DataProvider('outOfRangeFractionProvider')]
    public function rejectsAFractionOutsideZeroToOne(float $fraction): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('Fraction must be between 0 and 1');

        new FixedRandomizer(fraction: $fraction);
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function outOfRangeFractionProvider(): iterable
    {
        yield 'below zero' => [-0.1];
        yield 'above one' => [1.1];
    }

    public function rejectsAnInvertedRange(): void
    {
        Expect::exception(\InvalidArgumentException::class)
            ->withMessage('Minimum must be less than or equal to maximum');

        (new FixedRandomizer(fraction: 0.5))->float(min: 6.0, max: 2.0);
    }
}
