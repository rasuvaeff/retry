<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Sleeper;

use Rasuvaeff\Retry\Clock\FakeClock;

/**
 * @api
 */
final class FakeSleeper implements SleeperInterface
{
    /** @var list<int> */
    private array $delays = [];

    private ?FakeClock $clock = null;

    /** @var null|\Closure(int): void */
    private ?\Closure $onSleep = null;

    /**
     * A sleeper that also moves `$clock` forward by every delay it records, so
     * time-based state (a retry budget, a token bucket, a breaker cooldown)
     * observes the sleep without a real wait. `$onSleep` runs after the clock
     * has moved, with the slept milliseconds — use it to simulate what a
     * concurrent actor does while this one is asleep.
     *
     * @param null|\Closure(int): void $onSleep
     */
    public static function advancing(FakeClock $clock, ?\Closure $onSleep = null): self
    {
        $sleeper = new self();
        $sleeper->clock = $clock;
        $sleeper->onSleep = $onSleep;

        return $sleeper;
    }

    #[\Override]
    public function sleepMs(int $ms): void
    {
        if ($ms < 0) {
            throw new \InvalidArgumentException('Milliseconds must be non-negative');
        }

        $this->clock?->advanceMs(ms: $ms);
        $this->delays[] = $ms;

        if ($this->onSleep instanceof \Closure) {
            ($this->onSleep)($ms);
        }
    }

    /**
     * @return list<int>
     */
    public function delays(): array
    {
        return $this->delays;
    }
}
