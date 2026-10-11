<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Tests\Http;

use Rasuvaeff\Context\ContextCancelledException;
use Rasuvaeff\Retry\Clock\FakeClock;
use Rasuvaeff\Retry\Http\HttpAttemptRecord;
use Rasuvaeff\Retry\Http\HttpRetryExhausted;
use Rasuvaeff\Retry\Http\RetryingHttpClient;
use Rasuvaeff\Retry\Retry;
use Rasuvaeff\Retry\Sleeper\FakeSleeper;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(RetryingHttpClient::class)]
final class RetryingHttpClientHookErrorTest
{
    public function throwingOnRetryDoesNotAbortTheLoop(): void
    {
        $hookFailure = new FakeClientException(message: 'metrics down');
        /** @var list<\Throwable> $reported */
        $reported = [];
        $inner = new QueueHttpClient(items: [
            new FakeResponse(statusCode: 503),
            new FakeClientException(message: 'network'),
            new FakeResponse(statusCode: 200),
        ]);
        $client = new RetryingHttpClient(
            inner: $inner,
            policy: Retry::fixed(delayMs: 10, maxAttempts: 3)->withSleeper(sleeper: new FakeSleeper())->toPolicy(),
            clock: new FakeClock(),
            onRetry: [static function () use ($hookFailure): never {
                throw $hookFailure;
            }],
            onHookError: [static function (\Throwable $error) use (&$reported): void {
                $reported[] = $error;
            }],
        );

        $response = $client->sendRequest(request: new FakeRequest());

        Assert::same($response->getStatusCode(), 200);
        Assert::same($inner->calls(), 3);
        Assert::same($reported, [$hookFailure, $hookFailure]);
    }

    public function laterHooksStillRunWithoutAnErrorCallback(): void
    {
        /** @var list<int> $seen */
        $seen = [];
        $client = new RetryingHttpClient(
            inner: new QueueHttpClient(items: [
                new FakeResponse(statusCode: 503),
                new FakeResponse(statusCode: 200),
            ]),
            policy: Retry::fixed(delayMs: 10, maxAttempts: 2)->withSleeper(sleeper: new FakeSleeper())->toPolicy(),
            clock: new FakeClock(),
            onRetry: [
                static function (): never {
                    throw new \RuntimeException(message: 'first hook');
                },
                static function (HttpAttemptRecord $record) use (&$seen): void {
                    $seen[] = $record->attempt;
                },
            ],
        );

        Assert::same($client->sendRequest(request: new FakeRequest())->getStatusCode(), 200);
        Assert::same($seen, [1]);
    }

    public function throwingOnExhaustedKeepsTheExhaustionThrow(): void
    {
        $hookFailure = new \TypeError('bad hook');
        /** @var list<\Throwable> $reported */
        $reported = [];
        $client = new RetryingHttpClient(
            inner: new QueueHttpClient(items: [
                new FakeResponse(statusCode: 503),
                new FakeResponse(statusCode: 503),
            ]),
            policy: Retry::fixed(delayMs: 10, maxAttempts: 2)->withSleeper(sleeper: new FakeSleeper())->toPolicy(),
            clock: new FakeClock(),
            throwOnExhausted: true,
            onExhausted: [static function () use ($hookFailure): never {
                throw $hookFailure;
            }],
            onHookError: [static function (\Throwable $error) use (&$reported): void {
                $reported[] = $error;
            }],
        );

        try {
            $client->sendRequest(request: new FakeRequest());

            throw new \RuntimeException(message: 'Expected HttpRetryExhausted');
        } catch (HttpRetryExhausted $exhausted) {
            Assert::same($exhausted->attempts, 2);
            Assert::same($reported, [$hookFailure]);
        }
    }

    public function throwingOnExhaustedKeepsTheTransportException(): void
    {
        $failure = new FakeClientException(message: 'network');
        $client = new RetryingHttpClient(
            inner: new QueueHttpClient(items: [$failure]),
            policy: Retry::fixed(delayMs: 10, maxAttempts: 1)->withSleeper(sleeper: new FakeSleeper())->toPolicy(),
            clock: new FakeClock(),
            onExhausted: [static function (): never {
                throw new \RuntimeException(message: 'hook');
            }],
        );

        try {
            $client->sendRequest(request: new FakeRequest());

            throw new \RuntimeException(message: 'Expected FakeClientException');
        } catch (FakeClientException $exception) {
            Assert::same($exception, $failure);
        }
    }

    public function contextExceptionFromAHookPropagates(): void
    {
        $cancelled = new ContextCancelledException();
        $reported = 0;
        $inner = new QueueHttpClient(items: [
            new FakeResponse(statusCode: 503),
            new FakeResponse(statusCode: 200),
        ]);
        $client = new RetryingHttpClient(
            inner: $inner,
            policy: Retry::fixed(delayMs: 10, maxAttempts: 2)->withSleeper(sleeper: new FakeSleeper())->toPolicy(),
            clock: new FakeClock(),
            onRetry: [static function () use ($cancelled): never {
                throw $cancelled;
            }],
            onHookError: [static function () use (&$reported): void {
                $reported++;
            }],
        );

        try {
            $client->sendRequest(request: new FakeRequest());

            throw new \RuntimeException(message: 'Expected ContextCancelledException');
        } catch (ContextCancelledException $exception) {
            Assert::same($exception, $cancelled);
            Assert::same($inner->calls(), 1);
            Assert::same($reported, 0);
        }
    }
}
