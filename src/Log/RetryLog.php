<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Log;

use Psr\Log\LoggerInterface;

/**
 * Best-effort PSR-3 logging for the retry loop. Every method is a no-op when
 * the logger is null, so logging is purely additive to the callback hooks.
 *
 * @internal
 */
final readonly class RetryLog
{
    public function __construct(
        private ?LoggerInterface $logger,
    ) {}

    /**
     * Logs a retryable failure that will be followed by another attempt.
     */
    public function retry(
        int $attempt,
        int $delayMs,
        int $elapsedMs,
        ?\Throwable $exception,
        string $scope = 'operation',
    ): void {
        $this->logger?->warning(
            message: $scope . ' failed on attempt {attempt}, retrying in {delay_ms} ms',
            context: [
                'attempt' => $attempt,
                'delay_ms' => $delayMs,
                'elapsed_ms' => $elapsedMs,
                'exception' => $exception,
            ],
        );
    }

    /**
     * Logs the terminal state: attempts or budget exhausted.
     */
    public function exhausted(
        int $attempts,
        string $reason,
        ?\Throwable $exception,
        string $scope = 'operation',
    ): void {
        $this->logger?->error(
            message: $scope . ' retries exhausted after {attempts} attempts ({reason})',
            context: [
                'attempts' => $attempts,
                'reason' => $reason,
                'exception' => $exception,
            ],
        );
    }
}
