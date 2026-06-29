<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Sleeper;

/**
 * @api
 */
final readonly class SystemSleeper implements SleeperInterface
{
    #[\Override]
    public function sleepMs(int $ms): void
    {
        if ($ms < 0) {
            throw new \InvalidArgumentException('Milliseconds must be non-negative');
        }

        usleep(microseconds: $ms * 1000);
    }
}
