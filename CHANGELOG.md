# Changelog

## Unreleased

## 1.2.3 — 2026-08-21

- Fix a remote crash: a `Retry-After` delta-seconds of 16+ digits overflowed `int` on the `* 1000` and threw an uncaught `TypeError` — escaping the PSR-18 `ClientExceptionInterface` contract, so one hostile header could kill the consumer. Oversized values are now ignored like other unusable values (fall back to configured backoff).
- Fix the retry loop crashing on a backwards clock step (NTP correction): a negative elapsed made `AttemptRecord`'s constructor throw from inside the catch block, losing the operation's own exception. Elapsed time is now clamped at zero in both the closure and the HTTP paths.
- Rewind a seekable request body before every HTTP re-send: PSR-7 stream bodies are stateful, and attempt 2+ of a POST silently transmitted an empty body. Non-seekable bodies are documented as not safely retryable.
- Fix `Retry-After` HTTP-date strictness: `createFromFormat`'s `Y` accepted 1-3 digit years, so a malformed two-digit-year date parsed as ancient history and clamped to an immediate-hammer 0ms delay. Only values that round-trip the IMF-fixdate format exactly are accepted; everything else falls back to backoff.
- Replace deprecated `lcg_value()` (PHP 8.4+) in `SystemRandomizer` with `Random\Randomizer::getFloat()` — every jittered retry emitted `E_DEPRECATED` on PHP 8.4/8.5.

## 1.2.2 — 2026-08-21

- Migrate the property-based test suite from the frozen `rasuvaeff/property-testing` 2.x to the new `rasuvaeff/property-testing-testo` adapter (drop-in, no PHP code changes; same `#[Property]` attribute and `Gen` API).
- Adopt `rasuvaeff/rector-named-literals` and apply the named-argument rule to literal calls.
- Raise `rasuvaeff/property-testing-testo` to `^0.6`.
- Fix the docblock on `JitterMode::Additive`: it described a "symmetric factor... in (0, 1]", but `AdditiveJitter` is one-sided (spreads the delay downward only) and accepts a factor of `0` — this contradicted the class's own correct documentation everywhere else (README, `AGENTS.md`, llms.txt).
- Give the "Backward compatibility" CI job the same SKIPPED-report/intentional-boundary tolerance the other packages in this monorepo already carry, so a future intentional major (or roave reporting a static-analysis-limitation SKIPPED finding) doesn't permanently block the required status check.

## 1.2.1 — 2026-07-25

- Reject trailing newlines in `Retry-After` delta-seconds parsing: anchor the
  digit pattern in `RetryAfterParser` with `\z` instead of `$` (PCRE `$`
  matches before a trailing `\n`, which let `"120\n"` parse as 120s instead of
  falling through to HTTP-date parsing). Hygiene fix: the surrounding `(int)`
  cast masked the smuggling for delta-seconds, but the trailing newline still
  produced a different parse path than a clean value.

## 1.2.0 — 2026-07-25

- Ship an AI agent skill (`resources/skills/rasuvaeff-retry/SKILL.md` +
  `extra.skills` in composer.json): projects using the `llm/skills` Composer
  plugin get the skill synced into `.agents/skills/` automatically on install.
- Bump dev dependency `rasuvaeff/property-testing` from `^1.0` to `^2.6`.
- Make property-testing generator methods `public static` (a `private`
  generator is only reachable via reflection and gets removed by rector's
  `RemoveUnusedPrivateMethodRector`).

## 1.1.0 — 2026-07-04

- Add optional `rasuvaeff/duration` integration. Purely additive: the existing
  millisecond `int` API is unchanged and fully backwards-compatible.
- Duration-typed factories alongside the `int`-ms ones: `Retry::fixedFor()`,
  `Retry::exponentialFor()`, `Retry::withFixedFor()`,
  `Retry::withExponentialFor()`, `Retry::stopAfter()`, plus
  `RetryPolicy::fixedFor()` and `RetryPolicy::exponentialFor()`.
- `AttemptRecord::delay(): ?Duration` and `AttemptRecord::elapsed(): Duration`
  (and the same on `Http\HttpAttemptRecord`) expose the millisecond fields as
  `Duration` value objects.

## 1.0.1 — 2026-06-30

- Add `/benchmarks` and `/Makefile` to `.gitattributes` export-ignore.

## 1.0.0 — 2026-06-29

- Initial release: closure-first retry with fixed/exponential/immediate backoff,
  jitter, time budgets, testable clock/sleeper/randomizer abstractions, hooks,
  and a PSR-18 decorator.
- Backoff strategies `FixedBackoff`, `ExponentialBackoff`, `ImmediateBackoff`
  with named factories `Retry::fixed()`, `Retry::exponential()`,
  `Retry::immediate()` and `with*` builder counterparts.
- `jitter(float $factor, JitterMode $mode)` selects a strategy via the
  `Jitter\JitterMode` enum (`Additive`/`Full`/`None`); `factor` applies only to
  `Additive`. `AdditiveJitter` is equal jitter (spreads downward only), so the
  backoff `capMs` stays a hard ceiling. Strategies `AdditiveJitter`,
  `FullJitter`, `NoJitter` can also be injected with `withJitter()`.
- `ExponentialBackoff` clamps the delay before casting to int, so very high
  attempt numbers stay at `capMs` instead of overflowing to 0.
- Default retry triggers on any `\Exception`; `\Error` is not retried unless
  opted in with `retryOn(\Error::class)`. `retryOn()` replaces the class list,
  while `retryIf()`/`stopIf()` layer predicates.
- `retryIfResult(fn(mixed): bool)` retries when the operation returns an
  unacceptable value without throwing; on exhaustion the value is exposed via
  `RetryExhausted::lastException` as an `UnacceptableResult`.
- Clock uses PSR-20 (`Psr\Clock\ClockInterface`); ships `SystemClock` and a
  mutable `FakeClock` with `advanceMs()` for tests.
- `Retry::stopAfterMs(int)` caps total wall-clock time across attempts; attempt 1
  always runs, follow-ups are skipped when `elapsed + delay` would exceed the
  budget (before the `onRetry` hook and the sleep). Elapsed time is measured
  after the operation runs, so a slow attempt counts toward the budget.
- `AttemptRecord` carries `attempt`, `delayMs` (null on the terminal record),
  `elapsedMs`, and `exception`. `onRetry` receives a single `AttemptRecord`;
  `onExhausted` receives the `RetryExhausted` exception.
- `RetryExhausted` exposes `attempts`, `lastException`, `history`, and `reason`
  (`ExhaustionReason::MaxAttempts` or `ExhaustionReason::TimeBudget`).
  Non-retryable exceptions are rethrown as-is.
- `Http\RetryingHttpClient` decorates any PSR-18 client. `retryOnResponse` and
  `retryOnException` both receive the `RequestInterface` (gate on method/
  idempotency); `Http\RetryDecisions` ships `serverErrors()`, `rateLimited()`,
  `transient()`, and an `onlyIdempotent()` wrapper. Honors `Retry-After`
  (delta-seconds and IMF-fixdate) via `Http\RetryAfterParser`; the server delay
  replaces backoff without jitter and is capped by `maxRetryAfterMs` (default
  `300_000`, `null` disables). Supports a `budgetMs` time budget and a
  `retryOnException` filter for transport failures. `onRetry` hooks receive
  `Http\HttpAttemptRecord`; `onExhausted` hooks receive `Http\HttpRetryExhausted`
  (carrying `attempts` + `history`, mirroring the closure path) and fire on every
  exhaustion. With `throwOnExhausted: true` that same `Http\HttpRetryExhausted`
  (implements `ClientExceptionInterface`) is thrown. Disable header handling with
  `respectRetryAfter: false`.
- No Result dependency: wrap `Retry::run()` with `Result::fromThrowable()` from
  `rasuvaeff/result` (or equivalent) to obtain a typed result value.
