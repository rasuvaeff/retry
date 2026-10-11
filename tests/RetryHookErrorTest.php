<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Tests;

use Rasuvaeff\Context\ContextCancelledException;
use Rasuvaeff\Retry\AttemptRecord;
use Rasuvaeff\Retry\Retry;
use Rasuvaeff\Retry\RetryExhausted;
use Rasuvaeff\Retry\Sleeper\FakeSleeper;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(Retry::class)]
final class RetryHookErrorTest
{
    public function throwingOnRetryDoesNotAbortTheLoop(): void
    {
        $calls = 0;
        $hookFailure = new \RuntimeException(message: 'logger down');
        /** @var list<\Throwable> $reported */
        $reported = [];

        $value = Retry::fixed(delayMs: 10, maxAttempts: 3)
            ->withSleeper(sleeper: new FakeSleeper())
            ->onRetry(callback: static function () use ($hookFailure): never {
                throw $hookFailure;
            })
            ->onHookError(callback: static function (\Throwable $error) use (&$reported): void {
                $reported[] = $error;
            })
            ->run(operation: function () use (&$calls): string {
                if (++$calls < 3) {
                    throw new \RuntimeException(message: 'down');
                }

                return 'ok';
            });

        Assert::same($value, 'ok');
        Assert::same($calls, 3);
        Assert::same($reported, [$hookFailure, $hookFailure]);
    }

    public function throwingOnExhaustedKeepsTheRetryExhausted(): void
    {
        $operationFailure = new \RuntimeException(message: 'down');
        $hookFailure = new \TypeError('bad hook');
        /** @var list<\Throwable> $reported */
        $reported = [];

        try {
            Retry::fixed(delayMs: 10, maxAttempts: 2)
                ->withSleeper(sleeper: new FakeSleeper())
                ->onExhausted(callback: static function () use ($hookFailure): never {
                    throw $hookFailure;
                })
                ->onHookError(callback: static function (\Throwable $error) use (&$reported): void {
                    $reported[] = $error;
                })
                ->run(operation: static function () use ($operationFailure): never {
                    throw $operationFailure;
                });
            Assert::fail('Expected RetryExhausted');
        } catch (RetryExhausted $exhausted) {
            Assert::same($exhausted->lastException, $operationFailure);
            Assert::same($reported, [$hookFailure]);
        }
    }

    public function laterHooksStillRunAfterAThrowingOne(): void
    {
        /** @var list<int> $seen */
        $seen = [];
        $calls = 0;

        Retry::fixed(delayMs: 10, maxAttempts: 2)
            ->withSleeper(sleeper: new FakeSleeper())
            ->onRetry(callback: static function (): never {
                throw new \RuntimeException(message: 'first hook');
            })
            ->onRetry(callback: static function (AttemptRecord $record) use (&$seen): void {
                $seen[] = $record->attempt;
            })
            ->run(operation: function () use (&$calls): string {
                if (++$calls === 1) {
                    throw new \RuntimeException(message: 'down');
                }

                return 'ok';
            });

        Assert::same($seen, [1]);
    }

    public function everyHookErrorCallbackIsInvoked(): void
    {
        $first = 0;
        $second = 0;
        $calls = 0;

        Retry::fixed(delayMs: 10, maxAttempts: 2)
            ->withSleeper(sleeper: new FakeSleeper())
            ->onRetry(callback: static function (): never {
                throw new \RuntimeException(message: 'hook');
            })
            ->onHookError(callback: static function () use (&$first): void {
                $first++;
            })
            ->onHookError(callback: static function () use (&$second): void {
                $second++;
            })
            ->run(operation: function () use (&$calls): string {
                if (++$calls === 1) {
                    throw new \RuntimeException(message: 'down');
                }

                return 'ok';
            });

        Assert::same([$first, $second], [1, 1]);
    }

    public function contextExceptionFromAHookPropagates(): void
    {
        $cancelled = new ContextCancelledException();
        $reported = 0;
        $calls = 0;

        try {
            Retry::fixed(delayMs: 10, maxAttempts: 3)
                ->withSleeper(sleeper: new FakeSleeper())
                ->onRetry(callback: static function () use ($cancelled): never {
                    throw $cancelled;
                })
                ->onHookError(callback: static function () use (&$reported): void {
                    $reported++;
                })
                ->run(operation: function () use (&$calls): never {
                    $calls++;

                    throw new \RuntimeException(message: 'down');
                });
            Assert::fail('Expected ContextCancelledException');
        } catch (ContextCancelledException $exception) {
            Assert::same($exception, $cancelled);
            Assert::same($calls, 1);
            Assert::same($reported, 0);
        }
    }
}
