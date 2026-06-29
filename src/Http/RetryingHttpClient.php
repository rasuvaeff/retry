<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Http;

use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\Retry\Clock\SystemClock;
use Rasuvaeff\Retry\RetryPolicyInterface;

/**
 * @api
 */
final readonly class RetryingHttpClient implements ClientInterface
{
    /**
     * @param \Closure(ResponseInterface, RequestInterface): bool             $retryOnResponse
     * @param list<\Closure(HttpAttemptRecord): void>                         $onRetry
     * @param list<\Closure(HttpRetryExhausted): void>                        $onExhausted
     * @param null|\Closure(ClientExceptionInterface, RequestInterface): bool $retryOnException When null, all PSR-18 transport exceptions are retried.
     */
    public function __construct(
        private ClientInterface $inner,
        private RetryPolicyInterface $policy,
        private \Closure $retryOnResponse,
        private ClockInterface $clock = new SystemClock(),
        private bool $respectRetryAfter = true,
        private ?int $maxRetryAfterMs = 300_000,
        private ?int $budgetMs = null,
        private ?\Closure $retryOnException = null,
        private bool $throwOnExhausted = false,
        private array $onRetry = [],
        private array $onExhausted = [],
    ) {}

    /**
     * A single attempt budget covers both retryable responses and PSR-18
     * transport exceptions, so the inner client is called at most
     * `maxAttempts` times.
     *
     * Both `retryOnResponse` and `retryOnException` receive the `RequestInterface`
     * as their second argument, so retries can be gated on the request method
     * (e.g. idempotent methods only).
     *
     * When `respectRetryAfter` is enabled and a retryable response carries a
     * valid `Retry-After` header, that delay replaces the configured backoff
     * (no jitter; capped by `maxRetryAfterMs` if non-null). Transport exceptions
     * always fall back to backoff.
     *
     * Non-retryable transport exceptions are rethrown as-is (passthrough). When
     * retries are exhausted (by `maxAttempts` or `budgetMs`), every `onExhausted`
     * callback runs with the resulting {@see HttpRetryExhausted}. With
     * `throwOnExhausted` enabled that same exception is thrown; otherwise the last
     * retryable response is returned (or the last transport exception rethrown).
     *
     * @throws ClientExceptionInterface
     */
    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $maxAttempts = $this->policy->maxAttempts();
        $parser = $this->respectRetryAfter ? new RetryAfterParser(clock: $this->clock) : null;
        $startedAt = $this->clock->now();
        /** @var list<HttpAttemptRecord> $history */
        $history = [];

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $isLastAttempt = $attempt >= $maxAttempts;

            try {
                $response = $this->inner->sendRequest(request: $request);

                if (!($this->retryOnResponse)($response, $request)) {
                    return $response;
                }

                $elapsedMs = $this->elapsedMs(startedAt: $startedAt);
                $retryAfterMs = $parser?->parseMs(headerValue: $response->getHeaderLine('Retry-After'));
                $delayMs = $retryAfterMs !== null
                    ? $this->capRetryAfterMs($retryAfterMs)
                    : $this->backoffDelayMs(attempt: $attempt);

                if ($isLastAttempt || $this->budgetExceeded(elapsedMs: $elapsedMs, delayMs: $delayMs)) {
                    $history[] = new HttpAttemptRecord(
                        attempt: $attempt,
                        delayMs: null,
                        elapsedMs: $elapsedMs,
                        response: $response,
                        exception: null,
                    );
                    $exhausted = new HttpRetryExhausted(attempts: $attempt, history: $history);
                    $this->callExhausted(exhausted: $exhausted);

                    if ($this->throwOnExhausted) {
                        throw $exhausted;
                    }

                    return $response;
                }

                $record = new HttpAttemptRecord(
                    attempt: $attempt,
                    delayMs: $delayMs,
                    elapsedMs: $elapsedMs,
                    response: $response,
                    exception: null,
                );
                $history[] = $record;
                $this->callOnRetry(record: $record);
                $this->policy->sleeper()->sleepMs(ms: $delayMs);
            } catch (ClientExceptionInterface $exception) {
                if ($exception instanceof HttpRetryExhausted) {
                    throw $exception;
                }
                if ($this->retryOnException instanceof \Closure && !($this->retryOnException)($exception, $request)) {
                    throw $exception;
                }

                $elapsedMs = $this->elapsedMs(startedAt: $startedAt);
                $delayMs = $this->backoffDelayMs(attempt: $attempt);

                if ($isLastAttempt || $this->budgetExceeded(elapsedMs: $elapsedMs, delayMs: $delayMs)) {
                    $history[] = new HttpAttemptRecord(
                        attempt: $attempt,
                        delayMs: null,
                        elapsedMs: $elapsedMs,
                        response: null,
                        exception: $exception,
                    );
                    $exhausted = new HttpRetryExhausted(attempts: $attempt, history: $history, previous: $exception);
                    $this->callExhausted(exhausted: $exhausted);

                    if ($this->throwOnExhausted) {
                        throw $exhausted;
                    }

                    throw $exception;
                }

                $record = new HttpAttemptRecord(
                    attempt: $attempt,
                    delayMs: $delayMs,
                    elapsedMs: $elapsedMs,
                    response: null,
                    exception: $exception,
                );
                $history[] = $record;
                $this->callOnRetry(record: $record);
                $this->policy->sleeper()->sleepMs(ms: $delayMs);
            }
        }

        throw new \LogicException('Unreachable HTTP retry state');
    }

    private function backoffDelayMs(int $attempt): int
    {
        return $this->policy->jitter()->apply(
            delayMs: $this->policy->backoff()->delayMs(attempt: $attempt),
            attempt: $attempt,
            randomizer: $this->policy->randomizer(),
        );
    }

    private function capRetryAfterMs(int $retryAfterMs): int
    {
        if ($this->maxRetryAfterMs === null) {
            return $retryAfterMs;
        }

        return min($retryAfterMs, $this->maxRetryAfterMs);
    }

    private function budgetExceeded(int $elapsedMs, int $delayMs): bool
    {
        return $this->budgetMs !== null && $elapsedMs + $delayMs > $this->budgetMs;
    }

    private function elapsedMs(\DateTimeImmutable $startedAt): int
    {
        $now = $this->clock->now();

        return ($now->getTimestamp() - $startedAt->getTimestamp()) * 1000
            + (int) (((int) $now->format('u') - (int) $startedAt->format('u')) / 1000);
    }

    private function callExhausted(HttpRetryExhausted $exhausted): void
    {
        foreach ($this->onExhausted as $callback) {
            $callback($exhausted);
        }
    }

    private function callOnRetry(HttpAttemptRecord $record): void
    {
        foreach ($this->onRetry as $callback) {
            $callback($record);
        }
    }
}
