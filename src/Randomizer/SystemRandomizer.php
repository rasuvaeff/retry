<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Randomizer;

use Random\IntervalBoundary;
use Random\Randomizer;

/**
 * @api
 */
final readonly class SystemRandomizer implements RandomizerInterface
{
    private Randomizer $randomizer;

    public function __construct()
    {
        $this->randomizer = new Randomizer();
    }

    #[\Override]
    public function float(float $min, float $max): float
    {
        if ($min > $max) {
            throw new \InvalidArgumentException('Minimum must be less than or equal to maximum');
        }

        if ($min === $max) {
            return $min;
        }

        return $this->randomizer->getFloat($min, $max, IntervalBoundary::ClosedClosed);
    }
}
