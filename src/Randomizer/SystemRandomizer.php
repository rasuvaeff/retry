<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Randomizer;

/**
 * @api
 */
final readonly class SystemRandomizer implements RandomizerInterface
{
    #[\Override]
    public function float(float $min, float $max): float
    {
        if ($min > $max) {
            throw new \InvalidArgumentException('Minimum must be less than or equal to maximum');
        }

        return $min + (lcg_value() * ($max - $min));
    }
}
