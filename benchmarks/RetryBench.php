<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Benchmarks;

use Rasuvaeff\Retry\Retry;
use Rasuvaeff\Retry\Sleeper\FakeSleeper;
use Testo\Bench;

final class RetryBench
{
    private static ?Retry $retry = null;

    #[Bench(
        callables: [
            'plain' => [self::class, 'plainCall'],
        ],
        calls: 100_000,
        iterations: 10,
    )]
    public static function successfulRun(): int
    {
        return self::retry()->run(operation: static fn(): int => 42);
    }

    public static function plainCall(): int
    {
        return 42;
    }

    private static function retry(): Retry
    {
        if (self::$retry === null) {
            self::$retry = Retry::new()->withSleeper(sleeper: new FakeSleeper());
        }

        return self::$retry;
    }
}
