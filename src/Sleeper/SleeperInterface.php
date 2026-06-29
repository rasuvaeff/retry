<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Sleeper;

/**
 * @api
 */
interface SleeperInterface
{
    public function sleepMs(int $ms): void;
}
