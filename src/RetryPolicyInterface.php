<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry;

use Rasuvaeff\Retry\BackoffStrategy\BackoffStrategyInterface;
use Rasuvaeff\Retry\Jitter\JitterInterface;
use Rasuvaeff\Retry\Randomizer\RandomizerInterface;
use Rasuvaeff\Retry\Sleeper\SleeperInterface;

/**
 * @api
 */
interface RetryPolicyInterface
{
    public function maxAttempts(): int;

    public function backoff(): BackoffStrategyInterface;

    public function jitter(): JitterInterface;

    public function sleeper(): SleeperInterface;

    public function randomizer(): RandomizerInterface;
}
