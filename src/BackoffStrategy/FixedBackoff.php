<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\BackoffStrategy;

/**
 * @api
 */
final readonly class FixedBackoff implements BackoffStrategyInterface
{
    public function __construct(
        private int $delayMs,
    ) {
        if ($delayMs < 0) {
            throw new \InvalidArgumentException('Delay must be non-negative');
        }
    }

    #[\Override]
    public function delayMs(int $attempt): int
    {
        return $this->delayMs;
    }
}
