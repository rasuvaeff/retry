<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Tests\Sleeper;

use Rasuvaeff\Retry\Clock\FakeClock;
use Rasuvaeff\Retry\Sleeper\FakeSleeper;
use Rasuvaeff\Retry\Sleeper\SleeperInterface;

/**
 * Sleeper that advances a FakeClock by the same delay it records, so Retry's
 * elapsed-time accounting reflects the configured backoff without real waits.
 */
final readonly class ClockAdvancingSleeper implements SleeperInterface
{
    public FakeSleeper $inner;

    public function __construct(
        private FakeClock $clock,
    ) {
        $this->inner = new FakeSleeper();
    }

    #[\Override]
    public function sleepMs(int $ms): void
    {
        $this->clock->advanceMs(ms: $ms);
        $this->inner->sleepMs(ms: $ms);
    }

    /**
     * @return list<int>
     */
    public function delays(): array
    {
        return $this->inner->delays();
    }
}
