<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry;

/**
 * Why a retry loop gave up.
 *
 * @api
 */
enum ExhaustionReason
{
    /** All attempts were used on a retryable failure. */
    case MaxAttempts;

    /** `stopAfterMs()` budget was exceeded before another attempt could start. */
    case TimeBudget;
}
