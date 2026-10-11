<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Tests\Http;

use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\ContextCancelledException;
use Rasuvaeff\Retry\Clock\FakeClock;
use Rasuvaeff\Retry\Http\RetryDecisions;
use Rasuvaeff\Retry\Http\RetryingHttpClient;
use Rasuvaeff\Retry\Retry;
use Rasuvaeff\Retry\Sleeper\FakeSleeper;
use Rasuvaeff\Retry\Tests\Support\FakeTimeSource;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(RetryingHttpClient::class)]
#[Covers(RetryDecisions::class)]
final class RetryingHttpClientIdempotencyTest
{
    #[DataProvider('idempotentOnlyProvider')]
    public function postWithA503(bool $idempotentOnly, int $expectedCalls, int $expectedStatus): void
    {
        $sleeper = new FakeSleeper();
        $hooks = 0;
        $inner = new QueueHttpClient(items: [
            new FakeResponse(statusCode: 503),
            new FakeResponse(statusCode: 200),
        ]);
        $client = new RetryingHttpClient(
            inner: $inner,
            policy: Retry::fixed(delayMs: 10, maxAttempts: 2)->withSleeper(sleeper: $sleeper)->toPolicy(),
            clock: new FakeClock(),
            throwOnExhausted: true,
            onRetry: [static function () use (&$hooks): void {
                $hooks++;
            }],
            idempotentOnly: $idempotentOnly,
        );

        $response = $client->sendRequest(request: new FakeRequest(method: 'POST'));

        Assert::same($response->getStatusCode(), $expectedStatus);
        Assert::same($inner->calls(), $expectedCalls);
        Assert::same($hooks, $expectedCalls - 1);
    }

    #[DataProvider('idempotentOnlyProvider')]
    public function postWithATransportException(bool $idempotentOnly, int $expectedCalls, int $expectedStatus): void
    {
        $failure = new FakeClientException(message: 'network');
        $inner = new QueueHttpClient(items: [$failure, new FakeResponse(statusCode: 200)]);
        $client = new RetryingHttpClient(
            inner: $inner,
            policy: Retry::fixed(delayMs: 10, maxAttempts: 2)->withSleeper(sleeper: new FakeSleeper())->toPolicy(),
            clock: new FakeClock(),
            throwOnExhausted: true,
            idempotentOnly: $idempotentOnly,
        );

        try {
            $response = $client->sendRequest(request: new FakeRequest(method: 'POST'));
            Assert::same($response->getStatusCode(), $expectedStatus);
        } catch (FakeClientException $exception) {
            Assert::same($exception, $failure);
            Assert::true($idempotentOnly);
        }

        Assert::same($inner->calls(), $expectedCalls);
    }

    public static function idempotentOnlyProvider(): iterable
    {
        yield 'flag off retries' => [false, 2, 200];
        yield 'flag on passes through' => [true, 1, 503];
    }

    public function idempotentMethodsAreStillRetried(): void
    {
        $inner = new QueueHttpClient(items: [
            new FakeResponse(statusCode: 503),
            new FakeResponse(statusCode: 200),
        ]);
        $client = new RetryingHttpClient(
            inner: $inner,
            policy: Retry::fixed(delayMs: 10, maxAttempts: 2)->withSleeper(sleeper: new FakeSleeper())->toPolicy(),
            clock: new FakeClock(),
            idempotentOnly: true,
        );

        $response = $client->sendRequest(request: new FakeRequest(method: 'put'));

        Assert::same($response->getStatusCode(), 200);
        Assert::same($inner->calls(), 2);
    }

    public function passthroughStillHonoursACancelledContext(): void
    {
        [$context, $controller] = Context::background(timeSource: new FakeTimeSource())->withCancel();
        $controller->cancel();
        $inner = new QueueHttpClient(items: [new FakeResponse(statusCode: 200)]);
        $client = new RetryingHttpClient(
            inner: $inner,
            policy: Retry::fixed(delayMs: 10, maxAttempts: 2)->withSleeper(sleeper: new FakeSleeper())->toPolicy(),
            clock: new FakeClock(),
            context: $context,
            idempotentOnly: true,
        );

        try {
            $client->sendRequest(request: new FakeRequest(method: 'POST'));
            Assert::fail('Expected ContextCancelledException');
        } catch (ContextCancelledException) {
            Assert::same($inner->calls(), 0);
        }
    }
}
