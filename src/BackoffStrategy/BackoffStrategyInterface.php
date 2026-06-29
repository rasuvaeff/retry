<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\BackoffStrategy;

/**
 * @api
 */
interface BackoffStrategyInterface
{
    public function delayMs(int $attempt): int;
}
