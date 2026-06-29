<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Jitter;

use Rasuvaeff\Retry\Randomizer\RandomizerInterface;

/**
 * @api
 */
final readonly class FullJitter implements JitterInterface
{
    #[\Override]
    public function apply(int $delayMs, int $attempt, RandomizerInterface $randomizer): int
    {
        return (int) round($randomizer->float(min: 0.0, max: (float) $delayMs));
    }
}
