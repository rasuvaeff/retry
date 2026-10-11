<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Tests\Http;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\ContextCancelledException;
use Rasuvaeff\Context\ContextController;
use Rasuvaeff\Context\ContextExpiredException;
use Rasuvaeff\Retry\Clock\FakeClock;
use Rasuvaeff\Retry\Http\RetryingHttpClient;
use Rasuvaeff\Retry\Retry;
use Rasuvaeff\Retry\Sleeper\ContextAwareSleeper;
use Rasuvaeff\Retry\Sleeper\FakeSleeper;
use Rasuvaeff\Retry\Tests\Support\FakeTimeSource;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(RetryingHttpClient::class)]
#[Covers(ContextAwareSleeper::class)]
final class RetryingHttpClientContextTest
{
    public function cancelledContextStopsBeforeFirstAttempt(): void
    {
        [$context, $controller] = Context::background(timeSource: new FakeTimeSource())->withCancel();
        $controller->cancel();
        $inner = new QueueHttpClient(items: [new FakeResponse(statusCode: 200)]);
        $client = new RetryingHttpClient(
            inner: $inner,
            policy: Retry::fixed(delayMs: 10, maxAttempts: 3)->withSleeper(sleeper: new FakeSleeper())->toPolicy(),
            clock: new FakeClock(),
            context: $context,
        );

        try {
            $client->sendRequest(request: new FakeRequest());
            Assert::fail('Expected ContextCancelledException');
        } catch (ContextCancelledException) {
            Assert::same($inner->calls(), 0);
        }
    }

    public function cancellationDuringRetryAfterSleepStopsTheLoop(): void
    {
        [$context, $controller] = Context::background(timeSource: new FakeTimeSource())->withCancel();
        $sleeper = FakeSleeper::advancing(
            clock: new FakeClock(),
            onSleep: static function () use ($controller): void {
                $controller->cancel();
            },
        );
        $inner = new QueueHttpClient(items: [
            new FakeResponse(statusCode: 503, headers: ['Retry-After' => '120']),
            new FakeResponse(statusCode: 200),
        ]);
        $client = new RetryingHttpClient(
            inner: $inner,
            policy: Retry::fixed(delayMs: 10, maxAttempts: 3)->withSleeper(sleeper: $sleeper)->toPolicy(),
            clock: new FakeClock(),
            context: $context,
        );

        try {
            $client->sendRequest(request: new FakeRequest());
            Assert::fail('Expected ContextCancelledException');
        } catch (ContextCancelledException) {
            Assert::same($inner->calls(), 1);
            Assert::same($sleeper->delays(), [50]);
        }
    }

    public function deadlineExpiresDuringBackoffBeforeTheNextAttempt(): void
    {
        $time = new FakeTimeSource();
        [$context] = Context::background(timeSource: $time)->withTimeout(seconds: 0.08);
        $sleeper = FakeSleeper::advancing(
            clock: new FakeClock(),
            onSleep: static function (int $ms) use ($time): void {
                $time->advanceMs(ms: $ms);
            },
        );
        $inner = new QueueHttpClient(items: [
            new FakeClientException(message: 'network'),
            new FakeResponse(statusCode: 200),
        ]);
        $client = new RetryingHttpClient(
            inner: $inner,
            policy: Retry::fixed(delayMs: 500, maxAttempts: 3)->withSleeper(sleeper: $sleeper)->toPolicy(),
            clock: new FakeClock(),
            context: $context,
        );

        try {
            $client->sendRequest(request: new FakeRequest());
            Assert::fail('Expected ContextExpiredException');
        } catch (ContextExpiredException) {
            Assert::same($inner->calls(), 1);
            Assert::same($sleeper->delays(), [50, 30]);
        }
    }

    public function retriesWhileTheContextStaysActive(): void
    {
        $sleeper = new FakeSleeper();
        $inner = new QueueHttpClient(items: [
            new FakeResponse(statusCode: 503),
            new FakeResponse(statusCode: 200),
        ]);
        $client = new RetryingHttpClient(
            inner: $inner,
            policy: Retry::fixed(delayMs: 70, maxAttempts: 3)->withSleeper(sleeper: $sleeper)->toPolicy(),
            clock: new FakeClock(),
            context: Context::background(timeSource: new FakeTimeSource()),
        );

        $response = $client->sendRequest(request: new FakeRequest());

        Assert::same($response->getStatusCode(), 200);
        Assert::same($sleeper->delays(), [50, 20]);
    }

    public function responseReceivedAfterCancellationIsStillReturned(): void
    {
        [$context, $controller] = Context::background(timeSource: new FakeTimeSource())->withCancel();
        $inner = new class ($controller) implements ClientInterface {
            public function __construct(
                private readonly ContextController $controller,
            ) {}

            #[\Override]
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->controller->cancel();

                return new FakeResponse(statusCode: 201);
            }
        };
        $client = new RetryingHttpClient(
            inner: $inner,
            policy: Retry::fixed(delayMs: 10, maxAttempts: 3)->withSleeper(sleeper: new FakeSleeper())->toPolicy(),
            clock: new FakeClock(),
            context: $context,
        );

        Assert::same($client->sendRequest(request: new FakeRequest())->getStatusCode(), 201);
    }
}
