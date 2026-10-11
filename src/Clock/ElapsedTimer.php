<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Clock;

use Psr\Clock\ClockInterface;

/**
 * Measures elapsed milliseconds for retry budgets.
 *
 * With the real {@see SystemClock} the measurement is monotonic (`hrtime()`),
 * so an NTP step or a manual clock change neither shrinks nor inflates the
 * budget. Any other clock (a `FakeClock` in tests, a custom PSR-20 clock) is
 * read as-is, so tests that move a fake clock keep driving the budget.
 *
 * @internal
 */
final readonly class ElapsedTimer
{
    /**
     * @param \Closure(): int $elapsedMs
     */
    private function __construct(
        private \Closure $elapsedMs,
    ) {}

    /**
     * @param null|\Closure(): int $nanoseconds Monotonic source; defaults to `hrtime(true)`.
     */
    public static function start(ClockInterface $clock, ?\Closure $nanoseconds = null): self
    {
        if ($clock instanceof SystemClock) {
            $nanoseconds ??= static fn(): int => hrtime(as_number: true);
            $startedNs = $nanoseconds();

            return new self(elapsedMs: static fn(): int => intdiv($nanoseconds() - $startedNs, 1_000_000));
        }

        $startedAt = $clock->now();

        return new self(elapsedMs: static function () use ($clock, $startedAt): int {
            $now = $clock->now();

            return ($now->getTimestamp() - $startedAt->getTimestamp()) * 1000
                + (int) (((int) $now->format('u') - (int) $startedAt->format('u')) / 1000);
        });
    }

    /**
     * Clamped at zero: a clock stepping backwards would otherwise produce a
     * negative elapsed, and the attempt record's constructor would throw from
     * inside the retry machinery itself - losing the operation's own exception.
     *
     * @return int<0, max>
     */
    public function elapsedMs(): int
    {
        return max(0, ($this->elapsedMs)());
    }
}
