<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Http;

use Psr\Http\Client\ClientExceptionInterface;

/**
 * Describes an exhausted HTTP retry loop: passed to every `onExhausted` hook
 * and, when `throwOnExhausted` is enabled, thrown by {@see RetryingHttpClient}.
 * Implements `ClientExceptionInterface` so it stays within the PSR-18 contract
 * (`sendRequest()` may only throw that type).
 *
 * `$history` holds every recorded attempt; the terminal record carries the last
 * response or transport exception.
 *
 * @api
 */
final class HttpRetryExhausted extends \RuntimeException implements ClientExceptionInterface
{
    /**
     * @param list<HttpAttemptRecord> $history
     */
    public function __construct(
        public readonly int $attempts,
        public readonly array $history,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            message: sprintf('HTTP retries exhausted after %d attempt(s)', $attempts),
            previous: $previous,
        );
    }
}
