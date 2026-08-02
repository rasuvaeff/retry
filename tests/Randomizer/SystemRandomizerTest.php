<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Tests\Randomizer;

use Rasuvaeff\Retry\Randomizer\SystemRandomizer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(SystemRandomizer::class)]
final class SystemRandomizerTest
{
    public function returnsValueWithinRange(): void
    {
        $randomizer = new SystemRandomizer();

        for ($i = 0; $i < 200; $i++) {
            $value = $randomizer->float(min: 2.0, max: 6.0);

            Assert::true($value >= 2.0);
            Assert::true($value <= 6.0);
        }
    }

    public function returnsExactValueWhenMinEqualsMax(): void
    {
        $randomizer = new SystemRandomizer();

        Assert::same($randomizer->float(min: 3.0, max: 3.0), 3.0);
    }

    public function successiveCallsVary(): void
    {
        $randomizer = new SystemRandomizer();

        $values = [];
        for ($i = 0; $i < 20; $i++) {
            $values[] = $randomizer->float(min: 0.0, max: 1_000_000.0);
        }

        Assert::true(count(array_unique($values)) > 1);
    }

    public function rejectsMinAboveMax(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Minimum must be less than or equal to maximum');

        (new SystemRandomizer())->float(min: 5.0, max: 1.0);
    }
}
