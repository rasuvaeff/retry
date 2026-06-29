<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry;

/**
 * Thrown internally when a `retryIfResult()` predicate rejects the value
 * returned by the operation, so result-based retries flow through the same
 * machinery as exception-based ones. On exhaustion it is exposed as
 * `RetryExhausted::lastException`; read `$result` to inspect the rejected value.
 *
 * @api
 */
final class UnacceptableResult extends \RuntimeException
{
    public function __construct(
        public readonly mixed $result,
    ) {
        parent::__construct(message: 'Operation returned an unacceptable result');
    }
}
