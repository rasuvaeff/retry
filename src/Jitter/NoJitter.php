<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Jitter;

use Rasuvaeff\Retry\Randomizer\RandomizerInterface;

/**
 * @api
 */
final readonly class NoJitter implements JitterInterface
{
    #[\Override]
    public function apply(int $delayMs, int $attempt, RandomizerInterface $randomizer): int
    {
        return $delayMs;
    }
}
