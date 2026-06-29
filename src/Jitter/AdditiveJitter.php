<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Jitter;

use Rasuvaeff\Retry\Randomizer\RandomizerInterface;

/**
 * Equal jitter: spreads the delay over `[delayMs * (1 - factor), delayMs]`, so
 * the jittered value never exceeds the input delay and the backoff cap stays a
 * hard ceiling (matches the AWS "equal jitter" recommendation). The lower bound
 * shrinks with a larger `factor`; `factor = 1.0` reaches down to zero.
 *
 * @api
 */
final readonly class AdditiveJitter implements JitterInterface
{
    public function __construct(
        private float $factor,
    ) {
        if ($factor < 0.0 || $factor > 1.0) {
            throw new \InvalidArgumentException('Jitter factor must be between 0 and 1');
        }
    }

    #[\Override]
    public function apply(int $delayMs, int $attempt, RandomizerInterface $randomizer): int
    {
        $multiplier = 1.0 - $randomizer->float(min: 0.0, max: $this->factor);

        return (int) round((float) $delayMs * $multiplier);
    }
}
