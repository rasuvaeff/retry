---
name: rasuvaeff-retry
description: >-
  Closure-first retry for PHP with rasuvaeff/retry — Retry, RetryPolicy,
  RetryExhausted, AttemptRecord, backoff (FixedBackoff/ExponentialBackoff)
  with jitter, time budgets, FakeClock/FakeSleeper test doubles, and the
  PSR-18 Http\RetryingHttpClient decorator honoring Retry-After. Use when
  writing, reviewing or debugging retry/backoff logic, flaky-operation
  handling or HTTP retry in a project that has this package installed.
---

# rasuvaeff/retry

Closure-first retry with fixed/exponential backoff, jitter, time budgets and a
PSR-18 decorator. Namespace `Rasuvaeff\Retry\`.

## Safety rules — verify these on every change

1. **Retry only idempotent operations.** A retried non-idempotent call (POST
   payment, INSERT) can execute twice. For HTTP, gate with
   `RetryDecisions::onlyIdempotent($inner)` — it retries only
   GET/HEAD/PUT/DELETE/OPTIONS/TRACE.

2. **Always bound the retries.** Set `maxAttempts(n)` and/or `stopAfterMs()`
   (`budgetMs` on the HTTP client). Unbounded retries turn one outage into a
   pile-up. Use exponential backoff **with jitter** so many clients don't
   hammer the server in lockstep:

   ```php
   Retry::exponential(maxAttempts: 5, baseMs: 100, capMs: 30_000)
       ->jitter(factor: 0.2, mode: JitterMode::Additive)   // enum, not string
   ```

3. **Know what is retried.** The default retries any `\Exception`; `\Error` is
   NOT retried. `retryOn()` REPLACES the class list (narrow it deliberately);
   `retryIf()`/`stopIf()` layer extra predicates on top. Non-retryable
   exceptions are rethrown as-is; only exhaustion wraps into `RetryExhausted`.

4. **No real sleeps or wall-clock in tests.** Inject `FakeSleeper`, `FakeClock`
   (PSR-20, `advanceMs()`) and `FixedRandomizer` — every timing assertion must
   be deterministic.

5. **The PSR-18 decorator does not enforce timeouts.** `budgetMs` only stops
   scheduling further attempts; a hanging request hangs. Configure the timeout
   on the concrete HTTP client (Guzzle/Symfony), not via retry.

## Canonical usage

```php
use Rasuvaeff\Retry\Jitter\JitterMode;
use Rasuvaeff\Retry\Retry;

$value = Retry::new()
    ->maxAttempts(3)
    ->withExponential(baseMs: 100, multiplier: 2.0, capMs: 30_000)
    ->jitter(factor: 0.2, mode: JitterMode::Additive)
    ->retryOn(RuntimeException::class)
    ->stopAfterMs(budgetMs: 10_000)
    ->run(fn(): string => flakyOperation());   // value or throws RetryExhausted
```

HTTP: wrap any PSR-18 client in `Http\RetryingHttpClient` with a
`RetryPolicy`; it honors `Retry-After` (capped by `maxRetryAfterMs`).

## Full API

The complete reference — builder methods, `RetryExhausted`/`AttemptRecord`
fields, Duration-typed factories, all `Http\RetryingHttpClient` options —
ships with the package: read `vendor/rasuvaeff/retry/llms.txt` before guessing
a method name.
