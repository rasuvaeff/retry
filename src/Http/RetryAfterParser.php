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

            // (int) saturates at PHP_INT_MAX for oversized digit strings, and
            // `* 1000` overflows int into float, which the `?int` return type
            // would turn into a TypeError under strict_types - letting a
            // hostile server crash the caller with one header. A delay this
            // absurd is ignored like any other unusable value.
            if ($seconds > intdiv(\PHP_INT_MAX, 1000)) {
                return null;
            }

            return $seconds * 1000;
        }

        $target = \DateTimeImmutable::createFromFormat(self::HTTP_DATE_FORMAT, $headerValue);
        if ($target === false) {
            return null;
        }

        // createFromFormat's `Y` also accepts 1-3 digit years, so a malformed
        // "Thu, 21 Aug 26 10:00:00 GMT" would parse as the year 26 AD - far in
        // the past, clamping the delay to an immediate-hammer 0ms instead of
        // the documented null-then-backoff fallback. Only a value that
        // round-trips through the same format verbatim is a real IMF-fixdate.
        if ($target->format(self::HTTP_DATE_FORMAT) !== $headerValue) {
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
