<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Jitter;

use Rasuvaeff\Retry\Randomizer\RandomizerInterface;

/**
 * @api
 */
interface JitterInterface
{
    public function apply(int $delayMs, int $attempt, RandomizerInterface $randomizer): int;
}
