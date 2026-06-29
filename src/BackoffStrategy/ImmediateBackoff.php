<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\BackoffStrategy;

/**
 * @api
 */
final readonly class ImmediateBackoff implements BackoffStrategyInterface
{
    #[\Override]
    public function delayMs(int $attempt): int
    {
        return 0;
    }
}
