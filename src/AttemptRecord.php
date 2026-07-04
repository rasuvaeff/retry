<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry;

use Rasuvaeff\Duration\Duration;

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

    /**
     * The delay before the next attempt as a {@see Duration}, or `null` on the
     * terminal record (where {@see self::$delayMs} is `null`).
     */
    public function delay(): ?Duration
    {
        return $this->delayMs === null ? null : Duration::millis($this->delayMs);
    }

    /**
     * Elapsed time since the first attempt as a {@see Duration}.
     */
    public function elapsed(): Duration
    {
        return Duration::millis($this->elapsedMs);
    }
}
