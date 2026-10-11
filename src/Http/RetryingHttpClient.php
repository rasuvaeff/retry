<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Http;

use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\ContextException;
use Rasuvaeff\Retry\Clock\ElapsedTimer;
use Rasuvaeff\Retry\Clock\SystemClock;
use Rasuvaeff\Retry\Jitter\JitterInterface;
use Rasuvaeff\Retry\RetryPolicyInterface;
use Rasuvaeff\Retry\Sleeper\ContextAwareSleeper;

/**
 * @api
 */
final readonly class RetryingHttpClient implements ClientInterface
{
    /** @var \Closure(ResponseInterface, RequestInterface, int=): bool */
    private \Closure $retryOnResponse;

    private bool $responsePredicateTakesAttempt;

    private bool $exceptionPredicateTakesAttempt;

    /**
     * Both predicates may declare a third `int $attempt` parameter (1-based
     * number of the attempt whose outcome is being judged); it is passed only
     * to closures that declare at least three parameters or are variadic, so
     * existing two-argument closures keep working unchanged.
     *
     * @param null|\Closure(ResponseInterface, RequestInterface, int=): bool        $retryOnResponse  When null, {@see RetryDecisions::transient()} (408, 425, 429, 500, 502-504).
     * @param list<\Closure(HttpAttemptRecord): void>                              $onRetry
     * @param list<\Closure(HttpRetryExhausted): void>                             $onExhausted
     * @param null|\Closure(ClientExceptionInterface, RequestInterface, int=): bool $retryOnException When null, all PSR-18 transport exceptions are retried.
     * @param null|Context                                                         $context          Checked before every attempt and between 50 ms sleep slices; a {@see ContextException} is never retried.
     * @param bool                                                                 $idempotentOnly   When true, requests with a non-idempotent method ({@see RetryDecisions::isIdempotent()}) pass through with a single call: no retry, no hooks, no exhaustion.
     * @param null|JitterInterface                                                 $retryAfterJitter Spreads a `Retry-After` delay upwards only, never below the server's value; the `maxRetryAfterMs` cap still applies.
     * @param list<\Closure(\Throwable): void>                                     $onHookError      Receives exceptions thrown by `onRetry`/`onExhausted` hooks, which no longer abort the loop.
     */
    public function __construct(
        private ClientInterface $inner,
        private RetryPolicyInterface $policy,
        ?\Closure $retryOnResponse = null,
        private ClockInterface $clock = new SystemClock(),
        private bool $respectRetryAfter = true,
        private ?int $maxRetryAfterMs = 300_000,
        private ?int $budgetMs = null,
        private ?\Closure $retryOnException = null,
        private bool $throwOnExhausted = false,
        private array $onRetry = [],
        private array $onExhausted = [],
        private ?Context $context = null,
        private bool $idempotentOnly = false,
        private ?JitterInterface $retryAfterJitter = null,
        private array $onHookError = [],
    ) {
        $this->retryOnResponse = $retryOnResponse ?? RetryDecisions::transient();
        $this->responsePredicateTakesAttempt = $this->takesAttempt(predicate: $this->retryOnResponse);
        $this->exceptionPredicateTakesAttempt = $retryOnException instanceof \Closure
            && $this->takesAttempt(predicate: $retryOnException);
    }

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
     * (spread upwards by `retryAfterJitter` when set, then capped by
     * `maxRetryAfterMs` if non-null). Transport exceptions always fall back to
     * backoff.
     *
     * With a `context`, a cancelled or expired context throws its
     * {@see ContextException} before the next attempt or during a sleep. That
     * exception deliberately escapes the PSR-18 `ClientExceptionInterface`
     * contract: it is the caller's own cancellation, not a transport failure.
     * A final response (not retryable, or from the last attempt) is always
     * returned; when a retry is pending, the context ending throws instead,
     * after `onRetry` has fired for that attempt.
     *
     * `onRetry`/`onExhausted` hooks are best-effort: an exception from a hook
     * goes to `onHookError` and the loop continues (a `ContextException` from a
     * hook propagates).
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
        if ($this->idempotentOnly && !RetryDecisions::isIdempotent(request: $request)) {
            $this->context?->assertActive();

            return $this->inner->sendRequest(request: $request);
        }

        $maxAttempts = $this->policy->maxAttempts();
        $sleeper = $this->context instanceof Context
            ? new ContextAwareSleeper(inner: $this->policy->sleeper(), context: $this->context)
            : $this->policy->sleeper();
        $parser = $this->respectRetryAfter ? new RetryAfterParser(clock: $this->clock) : null;
        $timer = ElapsedTimer::start(clock: $this->clock);
        /** @var list<HttpAttemptRecord> $history */
        $history = [];

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $isLastAttempt = $attempt >= $maxAttempts;
            $this->context?->assertActive();

            // PSR-7 stream bodies are stateful: after attempt 1 the inner
            // client has read the stream to EOF, and a re-send would silently
            // transmit an empty body (whether it does depends on the client's
            // internals - the worst kind of selective). Rewind before every
            // retry; a non-seekable body cannot be replayed and is documented
            // as not safely retryable.
            if ($attempt > 1) {
                $body = $request->getBody();
                if ($body->isSeekable()) {
                    $body->rewind();
                }
            }

            try {
                $response = $this->inner->sendRequest(request: $request);

                $retry = $this->responsePredicateTakesAttempt
                    ? ($this->retryOnResponse)($response, $request, $attempt)
                    : ($this->retryOnResponse)($response, $request);
                if (!$retry) {
                    return $response;
                }

                $elapsedMs = $timer->elapsedMs();
                $retryAfterMs = $parser?->parseMs(headerValue: $response->getHeaderLine('Retry-After'));
                $delayMs = $retryAfterMs !== null
                    ? $this->retryAfterDelayMs(retryAfterMs: $retryAfterMs, attempt: $attempt)
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
                $sleeper->sleepMs(ms: $delayMs);
            } catch (ClientExceptionInterface $exception) {
                if ($exception instanceof HttpRetryExhausted) {
                    throw $exception;
                }
                if ($this->retryOnException instanceof \Closure) {
                    $retry = $this->exceptionPredicateTakesAttempt
                        ? ($this->retryOnException)($exception, $request, $attempt)
                        : ($this->retryOnException)($exception, $request);
                    if (!$retry) {
                        throw $exception;
                    }
                }

                $elapsedMs = $timer->elapsedMs();
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
                $sleeper->sleepMs(ms: $delayMs);
            }
        }

        throw new \LogicException('Unreachable HTTP retry state');
    }

    private function takesAttempt(\Closure $predicate): bool
    {
        $reflection = new \ReflectionFunction($predicate);

        return $reflection->isVariadic() || $reflection->getNumberOfParameters() >= 3;
    }

    private function backoffDelayMs(int $attempt): int
    {
        return $this->policy->jitter()->apply(
            delayMs: $this->policy->backoff()->delayMs(attempt: $attempt),
            attempt: $attempt,
            randomizer: $this->policy->randomizer(),
        );
    }

    private function retryAfterDelayMs(int $retryAfterMs, int $attempt): int
    {
        $delayMs = $retryAfterMs;
        if ($this->retryAfterJitter instanceof JitterInterface) {
            // Jitter implementations shrink a delay; mirror the shrink upwards
            // so the server's value stays the minimum.
            $spreadMs = max(0, $retryAfterMs - $this->retryAfterJitter->apply(
                delayMs: $retryAfterMs,
                attempt: $attempt,
                randomizer: $this->policy->randomizer(),
            ));
            $delayMs = $retryAfterMs + min($spreadMs, \PHP_INT_MAX - $retryAfterMs);
        }

        if ($this->maxRetryAfterMs === null) {
            return $delayMs;
        }

        return min($delayMs, $this->maxRetryAfterMs);
    }

    private function budgetExceeded(int $elapsedMs, int $delayMs): bool
    {
        return $this->budgetMs !== null && $elapsedMs + $delayMs > $this->budgetMs;
    }

    private function callExhausted(HttpRetryExhausted $exhausted): void
    {
        foreach ($this->onExhausted as $callback) {
            $this->callHook(hook: $callback, argument: $exhausted);
        }
    }

    private function callOnRetry(HttpAttemptRecord $record): void
    {
        foreach ($this->onRetry as $callback) {
            $this->callHook(hook: $callback, argument: $record);
        }
    }

    /**
     * @template T of object
     *
     * @param \Closure(T): void $hook
     * @param T $argument
     */
    private function callHook(\Closure $hook, object $argument): void
    {
        try {
            $hook($argument);
        } catch (ContextException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            foreach ($this->onHookError as $callback) {
                $callback($exception);
            }
        }
    }
}
