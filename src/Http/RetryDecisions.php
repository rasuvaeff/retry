<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Http;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Ready-to-use response predicates for {@see RetryingHttpClient::$retryOnResponse}.
 *
 * @api
 */
final readonly class RetryDecisions
{
    private const array IDEMPOTENT_METHODS = ['GET', 'HEAD', 'PUT', 'DELETE', 'OPTIONS', 'TRACE'];

    /**
     * Wraps a response predicate so retries only happen for idempotent request
     * methods (GET, HEAD, PUT, DELETE, OPTIONS, TRACE). Non-idempotent methods
     * such as POST and PATCH are never retried, avoiding duplicate side effects.
     *
     * @param \Closure(ResponseInterface): bool $inner
     *
     * @return \Closure(ResponseInterface, RequestInterface): bool
     */
    public static function onlyIdempotent(\Closure $inner): \Closure
    {
        return static fn(ResponseInterface $response, RequestInterface $request): bool => in_array(
            strtoupper($request->getMethod()),
            self::IDEMPOTENT_METHODS,
            strict: true,
        ) && $inner($response);
    }

    /**
     * Returns true for any 5xx response (server errors).
     *
     * @return \Closure(ResponseInterface): bool
     */
    public static function serverErrors(): \Closure
    {
        return static fn(ResponseInterface $response): bool => $response->getStatusCode() >= 500
            && $response->getStatusCode() < 600;
    }

    /**
     * Returns true for HTTP 429 (Too Many Requests). Pair with `respectRetryAfter`
     * to honor the server's `Retry-After` header.
     *
     * @return \Closure(ResponseInterface): bool
     */
    public static function rateLimited(): \Closure
    {
        return static fn(ResponseInterface $response): bool => $response->getStatusCode() === 429;
    }

    /**
     * Returns true for transient failures: 408, 425, 429, and the 5xx range
     * (500-504). 501 (Not Implemented) and status codes 505+ are excluded.
     *
     * @return \Closure(ResponseInterface): bool
     */
    public static function transient(): \Closure
    {
        return static fn(ResponseInterface $response): bool => in_array(
            $response->getStatusCode(),
            [408, 425, 429, 500, 502, 503, 504],
            strict: true,
        );
    }
}
