<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\BackoffStrategy;

/**
 * @api
 */
final readonly class ExponentialBackoff implements BackoffStrategyInterface
{
    public function __construct(
        private int $baseMs = 100,
        private float $multiplier = 2.0,
        private int $capMs = 30_000,
    ) {
        if ($baseMs < 0) {
            throw new \InvalidArgumentException('Base delay must be non-negative');
        }
        if ($multiplier < 1.0) {
            throw new \InvalidArgumentException('Multiplier must be greater than or equal to 1');
        }
        if ($capMs < 0) {
            throw new \InvalidArgumentException('Cap delay must be non-negative');
        }
    }

    #[\Override]
    public function delayMs(int $attempt): int
    {
        $exponent = (float) max(0, $attempt - 1);
        $factor = $this->multiplier ** $exponent;
        $delay = min((float) $this->baseMs * $factor, (float) $this->capMs);

        return (int) round($delay);
    }
}
