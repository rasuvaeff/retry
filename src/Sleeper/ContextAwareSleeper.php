<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Sleeper;

use Rasuvaeff\Context\Context;

/**
 * Sleeps in 50 ms slices, checking the context before and after every slice,
 * so a cancellation or an expired deadline interrupts a long backoff.
 *
 * @internal
 */
final readonly class ContextAwareSleeper implements SleeperInterface
{
    private const int SLICE_MS = 50;

    public function __construct(
        private SleeperInterface $inner,
        private Context $context,
    ) {}

    #[\Override]
    public function sleepMs(int $ms): void
    {
        $remaining = $ms;
        while ($remaining > 0) {
            $this->context->assertActive();
            $slice = min(self::SLICE_MS, $remaining, (int) ($this->context->remainingMs() ?? $remaining));
            $this->inner->sleepMs(ms: max(1, $slice));
            $remaining -= $slice;
        }
        $this->context->assertActive();
    }
}
