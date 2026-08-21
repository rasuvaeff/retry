<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Tests\Http;

use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\Retry\Clock\FakeClock;
use Rasuvaeff\Retry\Http\HttpAttemptRecord;
use Rasuvaeff\Retry\Http\HttpRetryExhausted;
use Rasuvaeff\Retry\Http\RetryingHttpClient;
use Rasuvaeff\Retry\Jitter\AdditiveJitter;
use Rasuvaeff\Retry\Randomizer\FixedRandomizer;
use Rasuvaeff\Retry\Retry;
use Rasuvaeff\Retry\RetryPolicy;
use Rasuvaeff\Retry\Sleeper\FakeSleeper;
use Rasuvaeff\Retry\Sleeper\SleeperInterface;
use Rasuvaeff\Retry\Tests\Sleeper\ClockAdvancingSleeper;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(RetryingHttpClient::class)]
#[Covers(HttpAttemptRecord::class)]
#[Covers(HttpRetryExhausted::class)]
#[Covers(RetryPolicy::class)]
#[Covers(Retry::class)]
final class RetryingHttpClientTest
{
    public function retriesRetryableResponses(): void
    {
        $sleeper = new FakeSleeper();
        $inner = new QueueHttpClient(items: [
            new FakeResponse(statusCode: 503),
            new FakeResponse(statusCode: 200),
        ]);
        $client = new RetryingHttpClient(
            inner: $inner,
            policy: $this->fixedPolicy(delayMs: 10, maxAttempts: 3, sleeper: $sleeper),
            retryOnResponse: fn(ResponseInterface $response): bool => $response->getStatusCode() >= 500,
            clock: new FakeClock(),
        );

        $response = $client->sendRequest(request: new FakeRequest());

        Assert::same($response->getStatusCode(), 200);
        Assert::same($inner->calls(), 2);
        Assert::same($sleeper->delays(), [10]);
    }

    public function retriesClientExceptions(): void
    {
        $sleeper = new FakeSleeper();
        $inner = new QueueHttpClient(items: [
            new FakeClientException(message: 'network'),
            new FakeResponse(statusCode: 200),
        ]);
        $client = new RetryingHttpClient(
            inner: $inner,
            policy: $this->fixedPolicy(delayMs: 10, maxAttempts: 2, sleeper: $sleeper),
            retryOnResponse: fn(ResponseInterface $response): bool => $response->getStatusCode() >= 500,
            clock: new FakeClock(),
        );

        $response = $client->sendRequest(request: new FakeRequest());

        Assert::same($response->getStatusCode(), 200);
        Assert::same($inner->calls(), 2);
        Assert::same($sleeper->delays(), [10]);
    }

    public function sharesAttemptBudgetBetweenResponsesAndExceptions(): void
    {
        $sleeper = new FakeSleeper();
        $inner = new QueueHttpClient(items: [
            new FakeResponse(statusCode: 503),
            new FakeClientException(message: 'network'),
            new FakeResponse(statusCode: 200),
        ]);
        $client = new RetryingHttpClient(
            inner: $inner,
            policy: $this->fixedPolicy(delayMs: 10, maxAttempts: 3, sleeper: $sleeper),
            retryOnResponse: fn(ResponseInterface $response): bool => $response->getStatusCode() >= 500,
            clock: new FakeClock(),
        );

        $response = $client->sendRequest(request: new FakeRequest());

        Assert::same($response->getStatusCode(), 200);
        Assert::same($inner->calls(), 3);
        Assert::same($sleeper->delays(), [10, 10]);
    }

    public function returnsLastResponseWhenAttemptsExhausted(): void
    {
        $sleeper = new FakeSleeper();
        $inner = new QueueHttpClient(items: [
            new FakeResponse(statusCode: 503),
            new FakeResponse(statusCode: 503),
            new FakeResponse(statusCode: 503),
        ]);
        $client = new RetryingHttpClient(
            inner: $inner,
            policy: $this->fixedPolicy(delayMs: 10, maxAttempts: 3, sleeper: $sleeper),
            retryOnResponse: fn(ResponseInterface $response): bool => $response->getStatusCode() >= 500,
            clock: new FakeClock(),
        );

        $response = $client->sendRequest(request: new FakeRequest());

        Assert::same($response->getStatusCode(), 503);
        Assert::same($inner->calls(), 3);
        Assert::same($sleeper->delays(), [10, 10]);
    }

    public function propagatesClientExceptionWhenAttemptsExhausted(): void
    {
        $sleeper = new FakeSleeper();
        $inner = new QueueHttpClient(items: [
            new FakeClientException(message: 'first'),
            new FakeClientException(message: 'last'),
        ]);
        $client = new RetryingHttpClient(
            inner: $inner,
            policy: $this->fixedPolicy(delayMs: 10, maxAttempts: 2, sleeper: $sleeper),
            retryOnResponse: fn(ResponseInterface $response): bool => $response->getStatusCode() >= 500,
            clock: new FakeClock(),
        );

        Expect::exception(FakeClientException::class)->withMessageContaining('last');

        $client->sendRequest(request: new FakeRequest());
    }

    public function doesNotRetryNonClientExceptions(): void
    {
        $sleeper = new FakeSleeper();
        $inner = new QueueHttpClient(items: [
            new \RuntimeException(message: 'bug'),
            new FakeResponse(statusCode: 200),
        ]);
        $client = new RetryingHttpClient(
            inner: $inner,
            policy: $this->fixedPolicy(delayMs: 10, maxAttempts: 3, sleeper: $sleeper),
            retryOnResponse: fn(ResponseInterface $response): bool => $response->getStatusCode() >= 500,
            clock: new FakeClock(),
        );

        try {
            $client->sendRequest(request: new FakeRequest());
        } catch (\RuntimeException $exception) {
            Assert::same($exception->getMessage(), 'bug');
            Assert::same($inner->calls(), 1);
            Assert::same($sleeper->delays(), []);

            return;
        }

        throw new \RuntimeException(message: 'Expected RuntimeException');
    }

    public function respectsIntegerRetryAfterHeader(): void
    {
        $sleeper = new FakeSleeper();
        $inner = new QueueHttpClient(items: [
            new FakeResponse(statusCode: 503, headers: ['Retry-After' => '2']),
            new FakeResponse(statusCode: 200),
        ]);
        $client = new RetryingHttpClient(
            inner: $inner,
            policy: $this->fixedPolicy(delayMs: 10, maxAttempts: 3, sleeper: $sleeper),
            retryOnResponse: fn(ResponseInterface $response): bool => $response->getStatusCode() >= 500,
            clock: new FakeClock(),
        );

        $client->sendRequest(request: new FakeRequest());

        Assert::same($sleeper->delays(), [2_000]);
    }

    public function respectsHttpDateRetryAfterHeader(): void
    {
        $sleeper = new FakeSleeper();
        $clock = new FakeClock(now: new \DateTimeImmutable('2025-06-01T12:00:00+00:00'));
        $inner = new QueueHttpClient(items: [
            new FakeResponse(
                statusCode: 503,
                headers: ['Retry-After' => 'Sun, 01 Jun 2025 12:00:05 GMT'],
            ),
            new FakeResponse(statusCode: 200),
        ]);
        $client = new RetryingHttpClient(
            inner: $inner,
            policy: $this->fixedPolicy(delayMs: 10, maxAttempts: 3, sleeper: $sleeper),
            retryOnResponse: fn(ResponseInterface $response): bool => $response->getStatusCode() >= 500,
            clock: $clock,
        );

        $client->sendRequest(request: new FakeRequest());

        Assert::same($sleeper->delays(), [5_000]);
    }

    public function fallsBackToBackoffWhenRetryAfterAbsent(): void
    {
        $sleeper = new FakeSleeper();
        $inner = new QueueHttpClient(items: [
            new FakeResponse(statusCode: 503),
            new FakeResponse(statusCode: 200),
        ]);
        $client = new RetryingHttpClient(
            inner: $inner,
            policy: $this->fixedPolicy(delayMs: 10, maxAttempts: 3, sleeper: $sleeper),
            retryOnResponse: fn(ResponseInterface $response): bool => $response->getStatusCode() >= 500,
            clock: new FakeClock(),
        );

        $client->sendRequest(request: new FakeRequest());

        Assert::same($sleeper->delays(), [10]);
    }

    public function ignoresInvalidRetryAfterHeader(): void
    {
        $sleeper = new FakeSleeper();
        $inner = new QueueHttpClient(items: [
            new FakeResponse(statusCode: 503, headers: ['Retry-After' => 'not-a-date']),
            new FakeResponse(statusCode: 200),
        ]);
        $client = new RetryingHttpClient(
            inner: $inner,
            policy: $this->fixedPolicy(delayMs: 10, maxAttempts: 3, sleeper: $sleeper),
            retryOnResponse: fn(ResponseInterface $response): bool => $response->getStatusCode() >= 500,
            clock: new FakeClock(),
        );

        $client->sendRequest(request: new FakeRequest());

        Assert::same($sleeper->delays(), [10]);
    }

    public function respectRetryAfterDisabledFallsBackToBackoff(): void
    {
        $sleeper = new FakeSleeper();
        $inner = new QueueHttpClient(items: [
            new FakeResponse(statusCode: 503, headers: ['Retry-After' => '2']),
            new FakeResponse(statusCode: 200),
        ]);
        $client = new RetryingHttpClient(
            inner: $inner,
            policy: $this->fixedPolicy(delayMs: 10, maxAttempts: 3, sleeper: $sleeper),
            retryOnResponse: fn(ResponseInterface $response): bool => $response->getStatusCode() >= 500,
            clock: new FakeClock(),
            respectRetryAfter: false,
        );

        $client->sendRequest(request: new FakeRequest());

        Assert::same($sleeper->delays(), [10]);
    }

    public function doesNotApplyJitterToRetryAfterDelay(): void
    {
        $sleeper = new FakeSleeper();
        $inner = new QueueHttpClient(items: [
            new FakeResponse(statusCode: 503, headers: ['Retry-After' => '2']),
            new FakeResponse(statusCode: 200),
        ]);
        $base = RetryPolicy::fixed(delayMs: 10, maxAttempts: 3);
        $client = new RetryingHttpClient(
            inner: $inner,
            policy: new RetryPolicy(
                maxAttempts: $base->maxAttempts(),
                backoff: $base->backoff(),
                jitter: new AdditiveJitter(factor: 1.0),
                sleeper: $sleeper,
                randomizer: new FixedRandomizer(fraction: 1.0),
            ),
            retryOnResponse: fn(ResponseInterface $response): bool => $response->getStatusCode() >= 500,
            clock: new FakeClock(),
        );

        $client->sendRequest(request: new FakeRequest());

        Assert::same($sleeper->delays(), [2_000]);
    }

    public function capsRetryAfterToConfiguredMaximum(): void
    {
        $sleeper = new FakeSleeper();
        $inner = new QueueHttpClient(items: [
            new FakeResponse(statusCode: 503, headers: ['Retry-After' => '600']),
            new FakeResponse(statusCode: 200),
        ]);
        $client = new RetryingHttpClient(
            inner: $inner,
            policy: $this->fixedPolicy(delayMs: 10, maxAttempts: 3, sleeper: $sleeper),
            retryOnResponse: fn(ResponseInterface $response): bool => $response->getStatusCode() >= 500,
            clock: new FakeClock(),
            maxRetryAfterMs: 5_000,
        );

        $client->sendRequest(request: new FakeRequest());

        Assert::same($sleeper->delays(), [5_000]);
    }

    public function maxRetryAfterNullDisablesCap(): void
    {
        $sleeper = new FakeSleeper();
        $inner = new QueueHttpClient(items: [
            new FakeResponse(statusCode: 503, headers: ['Retry-After' => '600']),
            new FakeResponse(statusCode: 200),
        ]);
        $client = new RetryingHttpClient(
            inner: $inner,
            policy: $this->fixedPolicy(delayMs: 10, maxAttempts: 3, sleeper: $sleeper),
            retryOnResponse: fn(ResponseInterface $response): bool => $response->getStatusCode() >= 500,
            clock: new FakeClock(),
            maxRetryAfterMs: null,
        );

        $client->sendRequest(request: new FakeRequest());

        Assert::same($sleeper->delays(), [600_000]);
    }

    public function onRetryReceivesHttpAttemptRecord(): void
    {
        $sleeper = new FakeSleeper();
        $inner = new QueueHttpClient(items: [
            new FakeResponse(statusCode: 503),
            new FakeResponse(statusCode: 200),
        ]);
        $records = [];
        $client = new RetryingHttpClient(
            inner: $inner,
            policy: $this->fixedPolicy(delayMs: 10, maxAttempts: 3, sleeper: $sleeper),
            retryOnResponse: fn(ResponseInterface $response): bool => $response->getStatusCode() >= 500,
            clock: new FakeClock(),
            onRetry: [
                function (HttpAttemptRecord $record) use (&$records): void {
                    $records[] = [$record->attempt, $record->delayMs, $record->response?->getStatusCode(), $record->exception];
                },
            ],
        );

        $client->sendRequest(request: new FakeRequest());

        Assert::same(count($records), 1);
        Assert::same($records[0], [1, 10, 503, null]);
    }

    public function onRetryReceivesRecordForExceptionRetry(): void
    {
        $sleeper = new FakeSleeper();
        $inner = new QueueHttpClient(items: [
            new FakeClientException(message: 'network'),
            new FakeResponse(statusCode: 200),
        ]);
        $records = [];
        $client = new RetryingHttpClient(
            inner: $inner,
            policy: $this->fixedPolicy(delayMs: 10, maxAttempts: 3, sleeper: $sleeper),
            retryOnResponse: fn(ResponseInterface $response): bool => $response->getStatusCode() >= 500,
            clock: new FakeClock(),
            onRetry: [
                function (HttpAttemptRecord $record) use (&$records): void {
                    $records[] = [$record->attempt, $record->delayMs, $record->response, $record->exception?->getMessage()];
                },
            ],
        );

        $client->sendRequest(request: new FakeRequest());

        Assert::same(count($records), 1);
        Assert::same($records[0], [1, 10, null, 'network']);
    }

    public function onExhaustedCalledWhenBudgetExceededOnResponse(): void
    {
        $clock = new FakeClock();
        $sleeper = new ClockAdvancingSleeper(clock: $clock);
        $inner = new QueueHttpClient(items: [
            new FakeResponse(statusCode: 503),
            new FakeResponse(statusCode: 503),
            new FakeResponse(statusCode: 503),
            new FakeResponse(statusCode: 200),
        ]);
        $exhaustedHistory = null;
        $client = new RetryingHttpClient(
            inner: $inner,
            policy: $this->fixedPolicy(delayMs: 200, maxAttempts: 10, sleeper: $sleeper),
            retryOnResponse: fn(ResponseInterface $response): bool => $response->getStatusCode() >= 500,
            clock: $clock,
            budgetMs: 300,
            onExhausted: [
                function (HttpRetryExhausted $reason) use (&$exhaustedHistory): void {
                    $exhaustedHistory = $reason->history;
                },
            ],
        );

        $response = $client->sendRequest(request: new FakeRequest());

        Assert::same($response->getStatusCode(), 503);
        Assert::same($inner->calls(), 2);
        Assert::same(count($exhaustedHistory ?? []), 2);
        Assert::null($exhaustedHistory[1]->delayMs);
    }

    public function retryOnExceptionPredicateFiltersWhichExceptionsAreRetried(): void
    {
        $sleeper = new FakeSleeper();
        $inner = new QueueHttpClient(items: [
            new FakeClientException(message: 'transient'),
            new FakeResponse(statusCode: 200),
        ]);
        $client = new RetryingHttpClient(
            inner: $inner,
            policy: $this->fixedPolicy(delayMs: 10, maxAttempts: 3, sleeper: $sleeper),
            retryOnResponse: fn(ResponseInterface $response): bool => $response->getStatusCode() >= 500,
            clock: new FakeClock(),
            retryOnException: fn(ClientExceptionInterface $e): bool => false,
        );

        try {
            $client->sendRequest(request: new FakeRequest());
        } catch (FakeClientException $exception) {
            Assert::same($exception->getMessage(), 'transient');
            Assert::same($inner->calls(), 1);
            Assert::same($sleeper->delays(), []);

            return;
        }

        throw new \RuntimeException(message: 'Expected FakeClientException');
    }

    public function budgetReturnsLastResponseWhenExceeded(): void
    {
        $clock = new FakeClock();
        $sleeper = new ClockAdvancingSleeper(clock: $clock);
        $inner = new QueueHttpClient(items: [
            new FakeResponse(statusCode: 503),
            new FakeResponse(statusCode: 503),
            new FakeResponse(statusCode: 200),
        ]);
        $client = new RetryingHttpClient(
            inner: $inner,
            policy: $this->fixedPolicy(delayMs: 200, maxAttempts: 10, sleeper: $sleeper),
            retryOnResponse: fn(ResponseInterface $response): bool => $response->getStatusCode() >= 500,
            clock: $clock,
            budgetMs: 100,
        );

        $response = $client->sendRequest(request: new FakeRequest());

        Assert::same($response->getStatusCode(), 503);
        Assert::same($inner->calls(), 1);
        Assert::same($sleeper->delays(), []);
    }

    public function retryOnResponseCanGateOnRequestMethod(): void
    {
        $sleeper = new FakeSleeper();
        $inner = new QueueHttpClient(items: [
            new FakeResponse(statusCode: 503),
            new FakeResponse(statusCode: 200),
        ]);
        $client = new RetryingHttpClient(
            inner: $inner,
            policy: $this->fixedPolicy(delayMs: 10, maxAttempts: 3, sleeper: $sleeper),
            retryOnResponse: fn(ResponseInterface $response, RequestInterface $request): bool
                => $request->getMethod() === 'GET' && $response->getStatusCode() >= 500,
            clock: new FakeClock(),
        );

        $response = $client->sendRequest(request: new FakeRequest(method: 'POST'));

        Assert::same($response->getStatusCode(), 503);
        Assert::same($inner->calls(), 1);
        Assert::same($sleeper->delays(), []);
    }

    public function retryOnExceptionCanGateOnRequestMethod(): void
    {
        $sleeper = new FakeSleeper();
        $inner = new QueueHttpClient(items: [
            new FakeClientException(message: 'network'),
            new FakeResponse(statusCode: 200),
        ]);
        $client = new RetryingHttpClient(
            inner: $inner,
            policy: $this->fixedPolicy(delayMs: 10, maxAttempts: 3, sleeper: $sleeper),
            retryOnResponse: fn(ResponseInterface $response): bool => $response->getStatusCode() >= 500,
            clock: new FakeClock(),
            retryOnException: fn(ClientExceptionInterface $exception, RequestInterface $request): bool
                => $request->getMethod() === 'GET',
        );

        try {
            $client->sendRequest(request: new FakeRequest(method: 'POST'));
        } catch (FakeClientException $exception) {
            Assert::same($exception->getMessage(), 'network');
            Assert::same($inner->calls(), 1);

            return;
        }

        throw new \RuntimeException(message: 'Expected FakeClientException');
    }

    public function onExhaustedFiresOnMaxAttempts(): void
    {
        $sleeper = new FakeSleeper();
        $inner = new QueueHttpClient(items: [
            new FakeResponse(statusCode: 503),
            new FakeResponse(statusCode: 503),
            new FakeResponse(statusCode: 503),
        ]);
        $exhausted = null;
        $client = new RetryingHttpClient(
            inner: $inner,
            policy: $this->fixedPolicy(delayMs: 10, maxAttempts: 3, sleeper: $sleeper),
            retryOnResponse: fn(ResponseInterface $response): bool => $response->getStatusCode() >= 500,
            clock: new FakeClock(),
            onExhausted: [
                function (HttpRetryExhausted $reason) use (&$exhausted): void {
                    $exhausted = $reason;
                },
            ],
        );

        $response = $client->sendRequest(request: new FakeRequest());

        Assert::same($response->getStatusCode(), 503);
        Assert::same($inner->calls(), 3);
        Assert::instanceOf($exhausted, HttpRetryExhausted::class);
        Assert::instanceOf($exhausted, ClientExceptionInterface::class);
        Assert::same($exhausted->attempts, 3);
        Assert::same(count($exhausted->history), 3);
        Assert::null($exhausted->history[2]->delayMs);
    }

    public function throwOnExhaustedThrowsHttpRetryExhausted(): void
    {
        $sleeper = new FakeSleeper();
        $inner = new QueueHttpClient(items: [
            new FakeResponse(statusCode: 503),
            new FakeResponse(statusCode: 503),
            new FakeResponse(statusCode: 503),
        ]);
        $client = new RetryingHttpClient(
            inner: $inner,
            policy: $this->fixedPolicy(delayMs: 10, maxAttempts: 3, sleeper: $sleeper),
            retryOnResponse: fn(ResponseInterface $response): bool => $response->getStatusCode() >= 500,
            clock: new FakeClock(),
            throwOnExhausted: true,
        );

        try {
            $client->sendRequest(request: new FakeRequest());
        } catch (HttpRetryExhausted $exception) {
            Assert::instanceOf($exception, ClientExceptionInterface::class);
            Assert::same($exception->attempts, 3);
            Assert::same(count($exception->history), 3);
            Assert::same($exception->history[2]->response?->getStatusCode(), 503);

            return;
        }

        throw new \RuntimeException(message: 'Expected HttpRetryExhausted');
    }

    public function throwOnExhaustedWrapsTransportException(): void
    {
        $sleeper = new FakeSleeper();
        $inner = new QueueHttpClient(items: [
            new FakeClientException(message: 'first'),
            new FakeClientException(message: 'last'),
        ]);
        $client = new RetryingHttpClient(
            inner: $inner,
            policy: $this->fixedPolicy(delayMs: 10, maxAttempts: 2, sleeper: $sleeper),
            retryOnResponse: fn(ResponseInterface $response): bool => $response->getStatusCode() >= 500,
            clock: new FakeClock(),
            throwOnExhausted: true,
        );

        try {
            $client->sendRequest(request: new FakeRequest());
        } catch (HttpRetryExhausted $exception) {
            Assert::same($exception->attempts, 2);
            Assert::same($exception->getPrevious()?->getMessage(), 'last');
            Assert::same(count($exception->history), 2);
            Assert::same($exception->history[1]->exception?->getMessage(), 'last');

            return;
        }

        throw new \RuntimeException(message: 'Expected HttpRetryExhausted');
    }

    public function onExhaustedFiresWhenTransportExceptionsExhaustAttempts(): void
    {
        $sleeper = new FakeSleeper();
        $inner = new QueueHttpClient(items: [
            new FakeClientException(message: 'first'),
            new FakeClientException(message: 'last'),
        ]);
        $exhaustedHistory = null;
        $client = new RetryingHttpClient(
            inner: $inner,
            policy: $this->fixedPolicy(delayMs: 10, maxAttempts: 2, sleeper: $sleeper),
            retryOnResponse: fn(ResponseInterface $response): bool => $response->getStatusCode() >= 500,
            clock: new FakeClock(),
            onExhausted: [
                function (HttpRetryExhausted $reason) use (&$exhaustedHistory): void {
                    $exhaustedHistory = $reason->history;
                },
            ],
        );

        try {
            $client->sendRequest(request: new FakeRequest());
        } catch (FakeClientException) {
            Assert::same(count($exhaustedHistory ?? []), 2);
            Assert::same($exhaustedHistory[1]->exception?->getMessage(), 'last');

            return;
        }

        throw new \RuntimeException(message: 'Expected FakeClientException');
    }

    public function budgetExactlyEqualToFirstDelayStillRetriesOnce(): void
    {
        $clock = new FakeClock();
        $sleeper = new ClockAdvancingSleeper(clock: $clock);
        $inner = new QueueHttpClient(items: [
            new FakeResponse(statusCode: 503),
            new FakeResponse(statusCode: 503),
        ]);
        $client = new RetryingHttpClient(
            inner: $inner,
            policy: $this->fixedPolicy(delayMs: 100, maxAttempts: 10, sleeper: $sleeper),
            retryOnResponse: fn(ResponseInterface $response): bool => $response->getStatusCode() >= 500,
            clock: $clock,
            budgetMs: 100,
        );

        $response = $client->sendRequest(request: new FakeRequest());

        Assert::same($response->getStatusCode(), 503);
        Assert::same($inner->calls(), 2);
    }

    public function elapsedMsAccountsForFullSecondsAndMillis(): void
    {
        $clock = new FakeClock(now: new \DateTimeImmutable('2025-01-01T00:00:00.250000+00:00'));
        $sleeper = new ClockAdvancingSleeper(clock: $clock);
        $inner = new QueueHttpClient(items: [
            new FakeResponse(statusCode: 503),
            new FakeResponse(statusCode: 503),
        ]);
        $exhaustedHistory = null;
        $client = new RetryingHttpClient(
            inner: $inner,
            policy: $this->fixedPolicy(delayMs: 1_999, maxAttempts: 2, sleeper: $sleeper),
            retryOnResponse: fn(ResponseInterface $response): bool => $response->getStatusCode() >= 500,
            clock: $clock,
            onExhausted: [
                function (HttpRetryExhausted $reason) use (&$exhaustedHistory): void {
                    $exhaustedHistory = $reason->history;
                },
            ],
        );

        $client->sendRequest(request: new FakeRequest());

        Assert::same($exhaustedHistory[1]->elapsedMs ?? -1, 1_999);
    }

    public function elapsedMsKeepsSubSecondMillisecondPrecision(): void
    {
        $clock = new FakeClock(now: new \DateTimeImmutable('2025-01-01T00:00:00.000000+00:00'));
        $sleeper = new ClockAdvancingSleeper(clock: $clock);
        $inner = new QueueHttpClient(items: [
            new FakeResponse(statusCode: 503),
            new FakeResponse(statusCode: 503),
        ]);
        $exhaustedHistory = null;
        $client = new RetryingHttpClient(
            inner: $inner,
            policy: $this->fixedPolicy(delayMs: 999, maxAttempts: 2, sleeper: $sleeper),
            retryOnResponse: fn(ResponseInterface $response): bool => $response->getStatusCode() >= 500,
            clock: $clock,
            onExhausted: [
                function (HttpRetryExhausted $reason) use (&$exhaustedHistory): void {
                    $exhaustedHistory = $reason->history;
                },
            ],
        );

        $client->sendRequest(request: new FakeRequest());

        Assert::same($exhaustedHistory[1]->elapsedMs ?? -1, 999);
    }

    /**
     * PSR-7 stream bodies are stateful: without an explicit rewind before the
     * re-send, attempt 2+ of a POST silently transmits an empty body (the
     * stream sits at EOF after attempt 1). The inner client here reads via
     * `getContents()`, as a byte-oriented transport would.
     */
    public function seekableRequestBodyIsRewoundBeforeEachRetry(): void
    {
        $body = new FakeStream(contents: '{"charge":100}');
        $seenBodies = [];
        $inner = new class($seenBodies) implements ClientInterface {
            /** @param list<string> $seenBodies */
            public function __construct(private array &$seenBodies) {}

            #[\Override]
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->seenBodies[] = $request->getBody()->getContents();

                return new FakeResponse(statusCode: \count($this->seenBodies) < 3 ? 503 : 200);
            }
        };
        $client = new RetryingHttpClient(
            inner: $inner,
            policy: $this->fixedPolicy(delayMs: 1, maxAttempts: 3, sleeper: new FakeSleeper()),
            retryOnResponse: fn(ResponseInterface $response): bool => $response->getStatusCode() >= 500,
            clock: new FakeClock(),
        );

        $response = $client->sendRequest(request: new FakeRequest(method: 'POST', body: $body));

        Assert::same($response->getStatusCode(), 200);
        Assert::same($seenBodies, ['{"charge":100}', '{"charge":100}', '{"charge":100}']);
        Assert::same($body->rewinds(), 2);
    }

    public function nonSeekableRequestBodyIsLeftUntouchedOnRetry(): void
    {
        $body = new FakeStream(contents: 'one-shot', seekable: false);
        $inner = new QueueHttpClient(items: [
            new FakeResponse(statusCode: 503),
            new FakeResponse(statusCode: 200),
        ]);
        $client = new RetryingHttpClient(
            inner: $inner,
            policy: $this->fixedPolicy(delayMs: 1, maxAttempts: 2, sleeper: new FakeSleeper()),
            retryOnResponse: fn(ResponseInterface $response): bool => $response->getStatusCode() >= 500,
            clock: new FakeClock(),
        );

        $response = $client->sendRequest(request: new FakeRequest(method: 'POST', body: $body));

        Assert::same($response->getStatusCode(), 200);
        Assert::same($body->rewinds(), 0);
    }

    /**
     * A backwards clock step (NTP correction) mid-run must not make the retry
     * machinery itself throw: a negative elapsed would blow up
     * HttpAttemptRecord's constructor and escape the PSR-18 contract.
     */
    public function backwardsClockStepDoesNotBreakTheRetryLoop(): void
    {
        $clock = new class implements ClockInterface {
            private int $calls = 0;

            #[\Override]
            public function now(): \DateTimeImmutable
            {
                // Second and later reads are 10s BEFORE the first one.
                return 0 === $this->calls++
                    ? new \DateTimeImmutable('2025-01-01T00:00:10+00:00')
                    : new \DateTimeImmutable('2025-01-01T00:00:00+00:00');
            }
        };
        $inner = new QueueHttpClient(items: [
            new FakeResponse(statusCode: 503),
            new FakeResponse(statusCode: 200),
        ]);
        $records = [];
        $client = new RetryingHttpClient(
            inner: $inner,
            policy: $this->fixedPolicy(delayMs: 1, maxAttempts: 2, sleeper: new FakeSleeper()),
            retryOnResponse: fn(ResponseInterface $response): bool => $response->getStatusCode() >= 500,
            clock: $clock,
            onRetry: [static function (HttpAttemptRecord $record) use (&$records): void {
                $records[] = $record;
            }],
        );

        $response = $client->sendRequest(request: new FakeRequest());

        Assert::same($response->getStatusCode(), 200);
        Assert::same($records[0]->elapsedMs, 0);
    }

    private function fixedPolicy(int $delayMs, int $maxAttempts, SleeperInterface $sleeper): RetryPolicy
    {
        $base = RetryPolicy::fixed(delayMs: $delayMs, maxAttempts: $maxAttempts);

        return new RetryPolicy(
            maxAttempts: $base->maxAttempts(),
            backoff: $base->backoff(),
            jitter: $base->jitter(),
            sleeper: $sleeper,
            randomizer: $base->randomizer(),
        );
    }
}
