<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Sleeper;

/**
 * @api
 */
final class FakeSleeper implements SleeperInterface
{
    /** @var list<int> */
    private array $delays = [];

    #[\Override]
    public function sleepMs(int $ms): void
    {
        if ($ms < 0) {
            throw new \InvalidArgumentException('Milliseconds must be non-negative');
        }

        $this->delays[] = $ms;
    }

    /**
     * @return list<int>
     */
    public function delays(): array
    {
        return $this->delays;
    }
}
