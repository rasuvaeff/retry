<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Tests\Http;

use Rasuvaeff\Retry\Clock\FakeClock;
use Rasuvaeff\Retry\Http\RetryingHttpClient;
use Rasuvaeff\Retry\Jitter\AdditiveJitter;
use Rasuvaeff\Retry\Jitter\FullJitter;
use Rasuvaeff\Retry\Jitter\JitterInterface;
use Rasuvaeff\Retry\Randomizer\FixedRandomizer;
use Rasuvaeff\Retry\Randomizer\RandomizerInterface;
use Rasuvaeff\Retry\Retry;
use Rasuvaeff\Retry\Sleeper\FakeSleeper;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(RetryingHttpClient::class)]
final class RetryingHttpClientRetryAfterJitterTest
{
    #[DataProvider('spreadProvider')]
    public function spreadsTheRetryAfterDelayUpwards(
        ?JitterInterface $jitter,
        float $fraction,
        ?int $maxRetryAfterMs,
        int $expectedDelayMs,
    ): void {
        $sleeper = new FakeSleeper();
        $inner = new QueueHttpClient(items: [
            new FakeResponse(statusCode: 429, headers: ['Retry-After' => '2']),
            new FakeResponse(statusCode: 200),
        ]);
        $client = new RetryingHttpClient(
            inner: $inner,
            policy: Retry::fixed(delayMs: 10, maxAttempts: 2)
                ->withSleeper(sleeper: $sleeper)
                ->withRandomizer(randomizer: new FixedRandomizer(fraction: $fraction))
                ->toPolicy(),
            clock: new FakeClock(),
            maxRetryAfterMs: $maxRetryAfterMs,
            retryAfterJitter: $jitter,
        );

        $client->sendRequest(request: new FakeRequest());

        Assert::same($sleeper->delays(), [$expectedDelayMs]);
    }

    public static function spreadProvider(): iterable
    {
        yield 'no jitter keeps the server value' => [null, 0.25, 300_000, 2_000];
        yield 'full jitter adds the shrunk part' => [new FullJitter(), 0.25, 300_000, 3_500];
        yield 'full jitter at the top adds nothing' => [new FullJitter(), 1.0, 300_000, 2_000];
        yield 'full jitter at the bottom doubles' => [new FullJitter(), 0.0, null, 4_000];
        yield 'additive jitter stays within its factor' => [new AdditiveJitter(factor: 0.5), 1.0, 300_000, 3_000];
        yield 'cap applies after the spread' => [new FullJitter(), 0.0, 3_000, 3_000];
    }

    public function jitterReturningMoreThanTheDelayNeverRetriesEarlier(): void
    {
        $sleeper = new FakeSleeper();
        $client = new RetryingHttpClient(
            inner: new QueueHttpClient(items: [
                new FakeResponse(statusCode: 503, headers: ['Retry-After' => '2']),
                new FakeResponse(statusCode: 200),
            ]),
            policy: Retry::fixed(delayMs: 10, maxAttempts: 2)->withSleeper(sleeper: $sleeper)->toPolicy(),
            clock: new FakeClock(),
            retryAfterJitter: new class implements JitterInterface {
                #[\Override]
                public function apply(int $delayMs, int $attempt, RandomizerInterface $randomizer): int
                {
                    return $delayMs * 3;
                }
            },
        );

        $client->sendRequest(request: new FakeRequest());

        Assert::same($sleeper->delays(), [2_000]);
    }

    public function jitterPassesTheAttemptNumber(): void
    {
        /** @var list<int> $attempts */
        $attempts = [];
        $client = new RetryingHttpClient(
            inner: new QueueHttpClient(items: [
                new FakeResponse(statusCode: 503, headers: ['Retry-After' => '1']),
                new FakeResponse(statusCode: 503, headers: ['Retry-After' => '1']),
                new FakeResponse(statusCode: 200),
            ]),
            policy: Retry::fixed(delayMs: 10, maxAttempts: 3)->withSleeper(sleeper: new FakeSleeper())->toPolicy(),
            clock: new FakeClock(),
            retryAfterJitter: new class ($attempts) implements JitterInterface {
                /** @param list<int> $attempts */
                public function __construct(private array &$attempts) {}

                #[\Override]
                public function apply(int $delayMs, int $attempt, RandomizerInterface $randomizer): int
                {
                    $this->attempts[] = $attempt;

                    return $delayMs;
                }
            },
        );

        $client->sendRequest(request: new FakeRequest());

        Assert::same($attempts, [1, 2]);
    }

    public function spreadSaturatesInsteadOfOverflowing(): void
    {
        $sleeper = new FakeSleeper();
        $client = new RetryingHttpClient(
            inner: new QueueHttpClient(items: [
                new FakeResponse(statusCode: 503, headers: ['Retry-After' => (string) intdiv(\PHP_INT_MAX, 1000)]),
                new FakeResponse(statusCode: 200),
            ]),
            policy: Retry::fixed(delayMs: 10, maxAttempts: 2)
                ->withSleeper(sleeper: $sleeper)
                ->withRandomizer(randomizer: new FixedRandomizer(fraction: 0.0))
                ->toPolicy(),
            clock: new FakeClock(),
            maxRetryAfterMs: null,
            retryAfterJitter: new FullJitter(),
        );

        $client->sendRequest(request: new FakeRequest());

        Assert::same($sleeper->delays(), [\PHP_INT_MAX]);
    }
}
