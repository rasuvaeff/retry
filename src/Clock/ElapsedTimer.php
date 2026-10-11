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
     * @param \Closure(): int $nanoseconds
     */
    private function __construct(
        private ?ClockInterface $clock,
        private ?\DateTimeImmutable $startedAt,
        private int $startedNs,
        private \Closure $nanoseconds,
    ) {}

    /**
     * @param null|\Closure(): int $nanoseconds Monotonic source; defaults to `hrtime(true)`.
     */
    public static function start(ClockInterface $clock, ?\Closure $nanoseconds = null): self
    {
        $nanoseconds ??= static fn(): int => (int) hrtime(as_number: true);

        if ($clock instanceof SystemClock) {
            return new self(clock: null, startedAt: null, startedNs: $nanoseconds(), nanoseconds: $nanoseconds);
        }

        return new self(clock: $clock, startedAt: $clock->now(), startedNs: 0, nanoseconds: $nanoseconds);
    }

    /**
     * @return int<0, max>
     */
    public function elapsedMs(): int
    {
        if (!$this->clock instanceof ClockInterface || !$this->startedAt instanceof \DateTimeImmutable) {
            return max(0, intdiv(($this->nanoseconds)() - $this->startedNs, 1_000_000));
        }

        $now = $this->clock->now();

        // Clamped at zero: a clock stepping backwards would otherwise produce
        // a negative elapsed, and the attempt record's constructor would throw
        // from inside the retry machinery itself - losing the operation's own
        // exception.
        return max(
            0,
            ($now->getTimestamp() - $this->startedAt->getTimestamp()) * 1000
                + (int) (((int) $now->format('u') - (int) $this->startedAt->format('u')) / 1000),
        );
    }
}
