<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Randomizer;

/**
 * @api
 */
final readonly class FixedRandomizer implements RandomizerInterface
{
    public function __construct(
        private float $fraction,
    ) {
        if ($fraction < 0.0 || $fraction > 1.0) {
            throw new \InvalidArgumentException('Fraction must be between 0 and 1');
        }
    }

    #[\Override]
    public function float(float $min, float $max): float
    {
        if ($min > $max) {
            throw new \InvalidArgumentException('Minimum must be less than or equal to maximum');
        }

        return $min + (($max - $min) * $this->fraction);
    }
}
