<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Randomizer;

/**
 * @api
 */
interface RandomizerInterface
{
    public function float(float $min, float $max): float;
}
