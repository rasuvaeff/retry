# AGENTS.md — retry

Guidance for AI agents working on this package. Read before changing code.

## What this is

This package provides closure-first retry for PHP 8.3+ with backoff, jitter,
time budgets, testable clock/sleeper/randomizer abstractions, hooks, and a
PSR-18 decorator that honors `Retry-After`. Public API lives in the
`Rasuvaeff\Retry` namespace.

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green
   `composer build`. "Should work" does not count.
2. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.
3. **No real sleeps or wall-clock waits in tests.** Use `FakeSleeper` for delays,
   `FakeClock` for elapsed-time and budget assertions, and deterministic
   randomizers for jitter. Every timing assertion must be deterministic.
4. **Preserve the public contract.** Update README + tests with any API change.

## Commands

No PHP/Composer on the host — run in Docker via the `composer:2` image.

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer psalm
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```

Or with Make:

```bash
make build
make cs-fix
make psalm
make test
make test-coverage
make mutation
make release-check
```

`composer.lock` is gitignored (library).

## Invariants & gotchas

- `Retry::fixed()`, `Retry::exponential()`, and `Retry::immediate()` are real
  static factories; their instance counterparts are `withFixed()`,
  `withExponential()`, and `withImmediate()`. There are no magic
  `__call`/`__callStatic` aliases — every entry point is statically analysable.
- Backoff and jitter delays depend only on the attempt number; there is no
  `previousDelayMs` plumbing (decorrelated jitter is intentionally out of scope).
- Clock uses PSR-20 (`Psr\Clock\ClockInterface`); there is no custom
  `ClockInterface`. Inject a `ClockInterface` via `Retry::withClock()` or pass it
  to `RetryingHttpClient` directly — it never lives on `RetryPolicy`. Both default
  to `Clock\SystemClock`; the HTTP clock affects `Retry-After` HTTP-date parsing
  and budget timing.
- `jitter(float $factor, JitterMode $mode)` takes a `Jitter\JitterMode` enum
  (`Additive`/`Full`/`None`), not a string; `factor` applies only to `Additive`.
  `AdditiveJitter` is equal jitter: it spreads the delay downward only
  (`[(1-factor)·delay, delay]`), so it never exceeds the input delay and `capMs`
  stays a hard ceiling. Changing this breaks the cap guarantee.
- The default `retryOn` is `[\Exception::class]` — `\Error` is NOT retried.
  `retryOn()` REPLACES the class list (so a narrower set can override the
  default); `retryIf()`/`stopIf()` append predicates. Keep that asymmetry.
- `retryIfResult(fn(mixed): bool)` retries an unacceptable return value by
  throwing an internal `UnacceptableResult` (which `shouldRetry()` always
  retries, bypassing `stopIf`). On exhaustion it surfaces as
  `RetryExhausted::lastException`; its `$result` holds the rejected value.
- `stopAfterMs(int)` caps total wall-clock time. Attempt 1 always runs; a
  follow-up attempt is skipped when `elapsedMs + delayMs > budgetMs`, so an
  unused sleep is not added. The budget check runs before the `onRetry` hook and
  `sleepMs()`, so neither fires for the skipped attempt. `elapsedMs` is measured
  AFTER the operation/request returns (so a slow attempt counts toward the
  budget), not at the top of the loop.
- `AttemptRecord` carries `attempt`, `delayMs`, `elapsedMs`, and `exception`.
  `delayMs` is `null` on the terminal record (max-attempts or budget exhaustion),
  where no sleep follows. `onRetry` callbacks receive a single `AttemptRecord`;
  `onExhausted` callbacks receive the `RetryExhausted` exception (not positional
  `int/int/Throwable` args). `RetryExhausted::history` is a `list<AttemptRecord>`
  and `RetryExhausted::reason` is an `ExhaustionReason` (`MaxAttempts` or
  `TimeBudget`).
- `RetryingHttpClient` uses a single attempt budget: retryable responses and
  PSR-18 `ClientExceptionInterface` failures share one `maxAttempts` loop. The
  retry predicates `retryOnResponse(ResponseInterface, RequestInterface)` and
  `retryOnException(ClientExceptionInterface, RequestInterface)` both receive the
  request (gate on method/idempotency); `Http\RetryDecisions` ships ready-made
  predicates plus `onlyIdempotent($inner)`. When a retryable response carries a
  valid `Retry-After` header, that delay replaces the configured backoff (no
  jitter; capped by `maxRetryAfterMs`, default `300_000`, `null` disables the
  cap). Transport exceptions always fall back to backoff and may be filtered with
  `retryOnException` (`null` retries all; non-matching are rethrown as-is).
  `budgetMs` caps total wall-clock time. `onRetry` callbacks take
  `Http\HttpAttemptRecord` (response xor exception); `onExhausted` callbacks take
  `Http\HttpRetryExhausted` (mirrors the closure path, where `onExhausted` gets
  `RetryExhausted`) carrying `attempts` + the full `history`. `onExhausted` fires
  on EVERY exhaustion (`maxAttempts` or `budgetMs`), recording a terminal record
  with `delayMs: null`. With `throwOnExhausted: true` it throws `Http\HttpRetryExhausted`
  (which MUST `implements ClientExceptionInterface` to honor PSR-18) carrying the
  history; otherwise it returns the last response / rethrows the last transport
  exception. The exhaustion throw on the response path is re-caught by the
  `catch (ClientExceptionInterface)` and re-thrown via an `instanceof
  HttpRetryExhausted` guard — keep that guard. Pass `respectRetryAfter: false` to
  disable header handling.
- `runSafe()` is intentionally absent. Retry returns a value or throws
  `RetryExhausted`. To obtain a `Result<T, RetryExhausted>` without exceptions,
  wrap `run()` with `Result::fromThrowable()` (or equivalent from your Result
  library). This package carries no Result dependency on purpose.
- Code: `declare(strict_types=1)`, `final readonly class`, `#[\Override]`, explicit types.
- `examples/` is part of the public contract: keep scripts runnable and update
  `examples/README.md` when example usage changes.
- **CI workflows are SHA-pinned.** Every `uses:` in `.github/workflows/*.yml`
  references a 40-char commit SHA with a `# vN` trailing comment. Never revert
  to floating `@vN` tags.

## When you finish

- Update `README.md` and examples when usage changes.
- Re-run `composer build`; if the change affects public API or release safety,
  also run `make release-check`.
