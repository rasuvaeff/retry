<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry;

/**
 * @api
 */
final class RetryExhausted extends \RuntimeException
{
    /**
     * @param list<AttemptRecord> $history
     */
    public function __construct(
        public readonly int $attempts,
        public readonly \Throwable $lastException,
        public readonly array $history,
        public readonly ExhaustionReason $reason,
    ) {
        $message = match ($reason) {
            ExhaustionReason::MaxAttempts => sprintf('Retry exhausted after %d attempt(s): %s', $attempts, $lastException->getMessage()),
            ExhaustionReason::TimeBudget => sprintf('Retry budget exceeded after %d attempt(s): %s', $attempts, $lastException->getMessage()),
        };

        parent::__construct(
            message: $message,
            previous: $lastException,
        );
    }
}
