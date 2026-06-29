<?php

declare(strict_types=1);

use Rasuvaeff\Retry\Clock\FakeClock;
use Rasuvaeff\Retry\Retry;
use Rasuvaeff\Retry\RetryExhausted;
use Rasuvaeff\Retry\Sleeper\SleeperInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Sleeper that advances a FakeClock instead of waiting, so the example is
 * deterministic and instant while elapsed-time accounting stays exact.
 */
final readonly class AdvancingSleeper implements SleeperInterface
{
    public function __construct(
        private FakeClock $clock,
    ) {}

    #[\Override]
    public function sleepMs(int $ms): void
    {
        $this->clock->advanceMs(ms: $ms);
    }
}

$clock = new FakeClock();
$calls = 0;

try {
    Retry::new()
        ->maxAttempts(maxAttempts: 20)
        ->withExponential(baseMs: 50, multiplier: 2.0)
        ->stopAfterMs(budgetMs: 500)
        ->withClock(clock: $clock)
        ->withSleeper(sleeper: new AdvancingSleeper(clock: $clock))
        ->run(operation: function () use (&$calls): string {
            $calls++;

            throw new RuntimeException(message: 'temporary failure');
        });
} catch (RetryExhausted $exception) {
    $last = $exception->history[count($exception->history) - 1];

    printf(
        "stopAfterMs(500ms) stopped retry after %d attempt(s); last elapsed %d ms, last delay %d ms (skipped)\n",
        $exception->attempts,
        $last->elapsedMs,
        $last->delayMs,
    );
}
