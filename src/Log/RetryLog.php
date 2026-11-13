<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Log;

use Psr\Log\LoggerInterface;

/**
 * Best-effort PSR-3 logging for the retry loop. Every method is a no-op when
 * the logger is null, and a throwing logger never breaks the loop: PSR-3
 * advises implementors not to throw, but a misbehaving one must not abort a
 * retry or replace the operation's outcome — logging is purely additive to
 * the callback hooks. There is nowhere left to report the failure (the logger
 * IS the reporting channel), so it is dropped.
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
     *
     * @param int|null $status the HTTP response status code when the retryable
     *                         outcome is a response rather than an exception
     */
    public function retry(
        int $attempt,
        int $delayMs,
        int $elapsedMs,
        ?\Throwable $exception,
        string $scope = 'operation',
        ?int $status = null,
    ): void {
        $context = [
            'attempt' => $attempt,
            'delay_ms' => $delayMs,
            'elapsed_ms' => $elapsedMs,
            'exception' => $exception,
        ];

        if ($status !== null) {
            $context['status'] = $status;
        }

        try {
            $this->logger?->warning(
                message: $scope . ' failed on attempt {attempt}, retrying in {delay_ms} ms',
                context: $context,
            );
        } catch (\Throwable) {
            // Best-effort by design: a broken logger must not abort the retry.
        }
    }

    /**
     * Logs the terminal outcome: attempts or budget exhausted.
     *
     * @param int|null $status the HTTP response status code when the terminal
     *                         outcome is a response rather than an exception
     */
    public function exhausted(
        int $attempts,
        string $reason,
        ?\Throwable $exception,
        string $scope = 'operation',
        ?int $status = null,
    ): void {
        $context = [
            'attempts' => $attempts,
            'reason' => $reason,
            'exception' => $exception,
        ];

        if ($status !== null) {
            $context['status'] = $status;
        }

        try {
            $this->logger?->error(
                message: $scope . ' retries exhausted after {attempts} attempts ({reason})',
                context: $context,
            );
        } catch (\Throwable) {
            // Best-effort by design: a broken logger must not replace the
            // exhaustion outcome or skip its hooks.
        }
    }
}
