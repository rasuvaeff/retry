<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Jitter;

/**
 * Selects a jitter strategy for `Retry::jitter()`.
 *
 * @api
 */
enum JitterMode
{
    /** One-sided: spreads the delay downward only, in [(1-factor)*delay, delay]. Requires a factor in [0, 1]. */
    case Additive;

    /** Random delay between zero and the computed backoff delay. */
    case Full;

    /** No jitter; the backoff delay is used as-is. */
    case None;
}
