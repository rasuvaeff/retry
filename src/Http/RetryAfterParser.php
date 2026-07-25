<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Http;

use Psr\Clock\ClockInterface;

/**
 * Parses an HTTP `Retry-After` header value into a delay in milliseconds.
 *
 * Supports delta-seconds (RFC 7231 §7.1.3) and IMF-fixdate (RFC 7231 §7.1.1.1),
 * the only HTTP-date form modern servers emit. The obsolete RFC 850 and asctime
 * date forms are intentionally not parsed and yield null. Returns null when the
 * header is absent, empty, zero, or unparseable, so the caller falls back to its
 * configured backoff.
 *
 * @api
 */
final readonly class RetryAfterParser
{
    private const string HTTP_DATE_FORMAT = 'D, d M Y H:i:s T';

    public function __construct(
        private ClockInterface $clock,
    ) {}

    /**
     * @return int|null Delay in ms, or null when the header should be ignored.
     */
    public function parseMs(?string $headerValue): ?int
    {
        if ($headerValue === null || $headerValue === '') {
            return null;
        }

        if (preg_match('/^\d+\z/', $headerValue) === 1) {
            $seconds = (int) $headerValue;
            if ($seconds === 0) {
                return null;
            }

            return $seconds * 1000;
        }

        $target = \DateTimeImmutable::createFromFormat(self::HTTP_DATE_FORMAT, $headerValue);
        if ($target === false) {
            return null;
        }

        $now = $this->clock->now();

        return max(
            0,
            ($target->getTimestamp() - $now->getTimestamp()) * 1000
                + (int) (((int) $target->format('u') - (int) $now->format('u')) / 1000),
        );
    }
}
