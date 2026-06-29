<?php

declare(strict_types=1);

use Rasuvaeff\Retry\Retry;
use Rasuvaeff\Retry\RetryExhausted;
use Rasuvaeff\Retry\Sleeper\FakeSleeper;
use Rasuvaeff\Retry\UnacceptableResult;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * `retryIfResult()` retries when the operation returns successfully but the value
 * is unacceptable — here, a job that stays "pending" until it reports "done".
 */
$sleeper = new FakeSleeper();
$poll = 0;

$status = Retry::new()
    ->maxAttempts(maxAttempts: 5)
    ->withFixed(delayMs: 50)
    ->withSleeper(sleeper: $sleeper)
    ->retryIfResult(predicate: fn(string $result): bool => $result === 'pending')
    ->run(operation: function () use (&$poll): string {
        $poll++;

        return $poll < 3 ? 'pending' : 'done';
    });

printf("status=%s polls=%d delays=%s\n", $status, $poll, implode(',', $sleeper->delays()));

/**
 * When the value never becomes acceptable, retries exhaust and the rejected
 * value is available via RetryExhausted::lastException (an UnacceptableResult).
 */
try {
    Retry::new()
        ->maxAttempts(maxAttempts: 2)
        ->withSleeper(sleeper: new FakeSleeper())
        ->retryIfResult(predicate: fn(string $result): bool => $result === 'pending')
        ->run(operation: fn(): string => 'pending');
} catch (RetryExhausted $exception) {
    $last = $exception->lastException;
    $rejected = $last instanceof UnacceptableResult ? $last->result : null;

    printf("exhausted reason=%s rejectedValue=%s\n", $exception->reason->name, var_export($rejected, true));
}
