<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry;

/**
 * @api
 */
final readonly class AttemptRecord
{
    public function __construct(
        public int $attempt,
        public ?int $delayMs,
        public int $elapsedMs,
        public \Throwable $exception,
    ) {
        if ($attempt < 1) {
            throw new \InvalidArgumentException('Attempt must be greater than or equal to 1');
        }
        if ($delayMs !== null && $delayMs < 0) {
            throw new \InvalidArgumentException('Delay must be non-negative');
        }
        if ($elapsedMs < 0) {
            throw new \InvalidArgumentException('Elapsed must be non-negative');
        }
    }
}
