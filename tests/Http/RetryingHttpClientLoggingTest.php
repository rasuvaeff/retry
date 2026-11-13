<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Tests\Http;

use Rasuvaeff\Retry\Clock\FakeClock;
use Rasuvaeff\Retry\Http\RetryingHttpClient;
use Rasuvaeff\Retry\Log\RetryLog;
use Rasuvaeff\Retry\Retry;
use Rasuvaeff\Retry\Sleeper\FakeSleeper;
use Rasuvaeff\Retry\Tests\Support\SpyLogger;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(RetryingHttpClient::class)]
#[Covers(RetryLog::class)]
final class RetryingHttpClientLoggingTest
{
    public function logsWarningPerRetryAndErrorOnResponseExhaustion(): void
    {
        $logger = new SpyLogger();
        $inner = new QueueHttpClient(items: [
            new FakeResponse(statusCode: 503),
            new FakeResponse(statusCode: 503),
            new FakeResponse(statusCode: 503),
        ]);
        $client = new RetryingHttpClient(
            inner: $inner,
            policy: Retry::fixed(delayMs: 10, maxAttempts: 3)->withSleeper(sleeper: new FakeSleeper())->toPolicy(),
            clock: new FakeClock(),
            logger: $logger,
        );

        $response = $client->sendRequest(request: new FakeRequest());

        Assert::same($response->getStatusCode(), 503);
        Assert::same(
            array_map(static fn(array $record): string => $record['level'], $logger->records),
            ['warning', 'warning', 'error'],
        );
        Assert::same(
            array_map(static fn(array $record): string => $record['message'], $logger->records),
            [
                'request failed on attempt {attempt}, retrying in {delay_ms} ms',
                'request failed on attempt {attempt}, retrying in {delay_ms} ms',
                'request retries exhausted after {attempts} attempts ({reason})',
            ],
        );
        Assert::same($logger->records[0]['context']['attempt'], 1);
        Assert::same($logger->records[0]['context']['delay_ms'], 10);
        Assert::null($logger->records[0]['context']['exception']);
        Assert::same($logger->records[0]['context']['status'], 503);
        Assert::same($logger->records[2]['context']['attempts'], 3);
        Assert::same($logger->records[2]['context']['reason'], 'MaxAttempts');
        Assert::same($logger->records[2]['context']['status'], 503);
    }

    public function logsTheTransportExceptionOnRetryAndExhaustion(): void
    {
        $logger = new SpyLogger();
        $failure = new FakeClientException(message: 'network');
        $inner = new QueueHttpClient(items: [$failure, $failure]);
        $client = new RetryingHttpClient(
            inner: $inner,
            policy: Retry::fixed(delayMs: 10, maxAttempts: 2)->withSleeper(sleeper: new FakeSleeper())->toPolicy(),
            clock: new FakeClock(),
            logger: $logger,
        );

        try {
            $client->sendRequest(request: new FakeRequest());
        } catch (\RuntimeException $exception) {
            Assert::same($exception, $failure);

            Assert::same(
                array_map(static fn(array $record): string => $record['level'], $logger->records),
                ['warning', 'error'],
            );
            Assert::same($logger->records[0]['context']['exception'], $failure);
            Assert::same($logger->records[1]['context']['exception'], $failure);
            Assert::same($logger->records[1]['context']['reason'], 'MaxAttempts');
            Assert::same($logger->records[1]['context']['attempts'], 2);

            return;
        }

        throw new \RuntimeException(message: 'Expected transport exception');
    }

    public function logsTimeBudgetReasonOnExhaustion(): void
    {
        $logger = new SpyLogger();
        $inner = new QueueHttpClient(items: [
            new FakeResponse(statusCode: 503),
            new FakeResponse(statusCode: 503),
        ]);
        $client = new RetryingHttpClient(
            inner: $inner,
            policy: Retry::fixed(delayMs: 10_000, maxAttempts: 5)->withSleeper(sleeper: new FakeSleeper())->toPolicy(),
            clock: new FakeClock(),
            budgetMs: 100,
            logger: $logger,
        );

        $response = $client->sendRequest(request: new FakeRequest());

        Assert::same($response->getStatusCode(), 503);
        Assert::same(count($logger->records), 1);
        Assert::same($logger->records[0]['level'], 'error');
        Assert::same($logger->records[0]['context']['reason'], 'TimeBudget');
        Assert::same($logger->records[0]['context']['attempts'], 1);
    }

    public function lastAttemptExhaustionReportsMaxAttemptsEvenWhenBudgetIsAlsoExceeded(): void
    {
        $logger = new SpyLogger();
        $clock = new FakeClock();
        $inner = new QueueHttpClient(items: [
            new FakeResponse(statusCode: 503),
            new FakeResponse(statusCode: 503),
        ]);
        $client = new RetryingHttpClient(
            inner: $inner,
            // The sleeper advances the clock, so attempt 2 sees elapsed 10 ms
            // and 10 + 10 > 15: the budget IS exceeded — but exhaustion happens
            // on the last attempt, so the reason must stay MaxAttempts (the
            // budget only wins when it cuts the loop short of maxAttempts).
            policy: Retry::fixed(delayMs: 10, maxAttempts: 2)
                ->withSleeper(sleeper: FakeSleeper::advancing(clock: $clock))
                ->toPolicy(),
            clock: $clock,
            budgetMs: 15,
            logger: $logger,
        );

        $response = $client->sendRequest(request: new FakeRequest());

        Assert::same($response->getStatusCode(), 503);
        Assert::same($logger->records[1]['level'], 'error');
        Assert::same($logger->records[1]['context']['reason'], 'MaxAttempts');
        Assert::same($logger->records[1]['context']['attempts'], 2);
    }

    public function lastAttemptExceptionExhaustionReportsMaxAttemptsEvenWhenBudgetIsAlsoExceeded(): void
    {
        $logger = new SpyLogger();
        $clock = new FakeClock();
        $failure = new FakeClientException(message: 'network');
        $inner = new QueueHttpClient(items: [$failure, $failure]);
        $client = new RetryingHttpClient(
            inner: $inner,
            policy: Retry::fixed(delayMs: 10, maxAttempts: 2)
                ->withSleeper(sleeper: FakeSleeper::advancing(clock: $clock))
                ->toPolicy(),
            clock: $clock,
            budgetMs: 15,
            logger: $logger,
        );

        try {
            $client->sendRequest(request: new FakeRequest());
        } catch (\RuntimeException $exception) {
            Assert::same($exception, $failure);
            Assert::same($logger->records[1]['context']['reason'], 'MaxAttempts');

            return;
        }

        throw new \RuntimeException(message: 'Expected transport exception');
    }
}
