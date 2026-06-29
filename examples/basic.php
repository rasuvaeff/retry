<?php

declare(strict_types=1);

use Rasuvaeff\Retry\Retry;
use Rasuvaeff\Retry\Sleeper\FakeSleeper;

require dirname(__DIR__) . '/vendor/autoload.php';

$sleeper = new FakeSleeper();
$calls = 0;

$value = Retry::new()
    ->maxAttempts(maxAttempts: 3)
    ->withFixed(delayMs: 50)
    ->withSleeper(sleeper: $sleeper)
    ->run(operation: function () use (&$calls): string {
        $calls++;
        if ($calls < 2) {
            throw new RuntimeException(message: 'temporary failure');
        }

        return 'ok';
    });

echo sprintf("value=%s calls=%d delays=%s\n", $value, $calls, implode(',', $sleeper->delays()));
