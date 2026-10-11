<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Tests\Support;

use Rasuvaeff\Context\TimeSource;

final class FakeTimeSource implements TimeSource
{
    private int $nowNs = 1_000_000_000;

    #[\Override]
    public function nowNs(): int
    {
        return $this->nowNs;
    }

    public function advanceMs(int $ms): void
    {
        $this->nowNs += $ms * 1_000_000;
    }
}
