# rasuvaeff/retry

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/retry/v)](https://packagist.org/packages/rasuvaeff/retry)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/retry/downloads)](https://packagist.org/packages/rasuvaeff/retry)
[![Build](https://github.com/rasuvaeff/retry/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/retry/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/retry/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/retry/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/retry/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/retry/php)](https://packagist.org/packages/rasuvaeff/retry)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)
[Русская версия](README.ru.md)

Closure-first retry with fixed/exponential backoff, full/additive jitter, time
budgets, testable clock/sleeper/randomizer interfaces, observability hooks, and
a PSR-18 HTTP client decorator that honors `Retry-After`.

> Using an AI coding assistant? [llms.txt](llms.txt) contains a compact API reference you can share with the model.

## Requirements

- PHP 8.3+
- `psr/clock` ^1.0
- `psr/http-client` ^1.0
- `psr/http-message` ^1.0 || ^2.0
- `rasuvaeff/duration` ^1.0

## Installation

```bash
composer require rasuvaeff/retry
```

## Usage

```php
use Rasuvaeff\Retry\Retry;

$value = Retry::new()
    ->maxAttempts(3)
    ->withExponential(baseMs: 100, multiplier: 2.0, capMs: 30_000)
    ->jitter(factor: 0.2)
    ->retryOn(RuntimeException::class)
    ->retryIf(fn(Throwable $e): bool => $e->getCode() >= 500)
    ->stopIf(fn(Throwable $e): bool => $e instanceof InvalidArgumentException)
    ->stopAfterMs(budgetMs: 10_000)
    ->onRetry(fn(AttemptRecord $record): null => null)
    ->run(fn(): string => flakyOperation());
```

Named policy factories return a ready-to-use builder:

```php
Retry::fixed(delayMs: 500, maxAttempts: 3);
Retry::exponential(maxAttempts: 3);
Retry::immediate(maxAttempts: 3);
```

Tune a builder with the `with*` methods:

```php
Retry::new()->withExponential(baseMs: 100, multiplier: 2.0, capMs: 30_000);
Retry::new()->withFixed(delayMs: 500);
Retry::new()->withImmediate();
Retry::new()->withClock(new SystemClock());
```

Every factory and builder method is a real, statically analysable method — there
are no magic `__call`/`__callStatic` aliases.

### Duration

Every millisecond `int` entry point has a `rasuvaeff/duration` counterpart, so
you can express delays and budgets as type-safe `Duration` value objects instead
of ambiguous integers. The `int`-ms API is unchanged; the Duration variants are
purely additive.

```php
use Rasuvaeff\Duration\Duration;

Retry::fixedFor(delay: Duration::seconds(1), maxAttempts: 3);
Retry::exponentialFor(base: Duration::millis(100), cap: Duration::seconds(30));

Retry::new()
    ->withExponentialFor(base: Duration::millis(100), cap: Duration::seconds(30))
    ->stopAfter(budget: Duration::seconds(10));

RetryPolicy::fixedFor(delay: Duration::seconds(1));
```

`AttemptRecord` and `Http\HttpAttemptRecord` expose the same values on the way
out: `delay(): ?Duration` (null on the terminal record) and
`elapsed(): Duration`, alongside the existing `delayMs`/`elapsedMs` fields.

By default a retry triggers on any `\Exception`. `\Error` (e.g. `\TypeError`,
assertion failures) is **not** retried — opt in explicitly with
`retryOn(\Error::class)`. `retryOn()` **replaces** the class list (so you can
narrow the default), while `retryIf()` and `stopIf()` layer extra predicates.

### Jitter

`jitter()` selects a strategy with the `Jitter\JitterMode` enum. `factor`
applies only to `Additive` (it is ignored for `Full` and `None`).

```php
use Rasuvaeff\Retry\Jitter\JitterMode;

Retry::new()->jitter(factor: 0.2, mode: JitterMode::Additive); // equal jitter: [(1-factor)·delay, delay]
Retry::new()->jitter(mode: JitterMode::Full);                  // random in [0, delay]
Retry::new()->jitter(mode: JitterMode::None);                  // no jitter
```

`Additive` is "equal jitter": it only spreads the delay **downward**, so the
jittered value never exceeds the backoff delay and `capMs` stays a hard ceiling.
Or inject a strategy directly with `withJitter(new Jitter\FullJitter())`.

### Retrying on a returned value

`retryIfResult()` retries when the operation returns successfully but the value
is unacceptable (no exception needed). On exhaustion the rejected value is
available via `RetryExhausted::lastException` (an `UnacceptableResult`).

```php
$value = Retry::new()
    ->maxAttempts(5)
    ->retryIfResult(fn(Response $r): bool => $r->status === 'pending')
    ->run(fn(): Response => $api->poll());
```

### Time budget

`stopAfterMs()` caps the total wall-clock time spent retrying. Attempt 1 always
runs; a follow-up attempt is skipped when `elapsed + nextDelay` would exceed the
budget, so unused sleep time is not added.

```php
Retry::new()
    ->maxAttempts(maxAttempts: 10)
    ->withExponential(baseMs: 200)
    ->stopAfterMs(budgetMs: 5_000)
    ->run(fn(): string => flakyOperation());
```

Each `AttemptRecord` in `RetryExhausted::history` carries `elapsedMs`, the
milliseconds between `run()` start and that attempt. `onRetry` callbacks receive
the full `AttemptRecord`; `onExhausted` callbacks receive the `RetryExhausted`.
`RetryExhausted::reason` is an `ExhaustionReason` (`MaxAttempts` or `TimeBudget`)
explaining why the loop gave up. The terminal `AttemptRecord` has `delayMs` of
`null` (no sleep followed it).

### Result integration

This package does not depend on a Result type. To get a `Result<T, RetryExhausted>`
without exceptions, wrap `run()` with your own Result library. With
`rasuvaeff/result`:

```php
use Rasuvaeff\Result\Result;

$result = Result::fromThrowable(
    fn() => Retry::new()->maxAttempts(3)->run(fn(): string => flakyOperation()),
);

$value = $result->unwrapOr('fallback');
```

`Result::fromThrowable()` catches any `Throwable` from `run()` (including
`RetryExhausted`), so the error channel is `Throwable` unless you narrow it with
`mapErr()`.

### PSR-18 decorator

`RetryingHttpClient` decorates any PSR-18 client. `retryOnResponse` decides which
responses are retried; `Http\RetryDecisions` ships ready-made predicates.

```php
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\Retry\Http\HttpAttemptRecord;
use Rasuvaeff\Retry\Http\HttpRetryExhausted;
use Rasuvaeff\Retry\Http\RetryDecisions;
use Rasuvaeff\Retry\Http\RetryingHttpClient;
use Rasuvaeff\Retry\RetryPolicy;

$client = new RetryingHttpClient(
    inner: $psr18Client,
    policy: RetryPolicy::exponential(maxAttempts: 3, baseMs: 100),
    retryOnResponse: RetryDecisions::onlyIdempotent(RetryDecisions::transient()),
    respectRetryAfter: true,
    maxRetryAfterMs: 300_000,
    budgetMs: null,
    retryOnException: null,
    throwOnExhausted: false,
    onRetry: [fn(HttpAttemptRecord $record): null => null],
    onExhausted: [fn(HttpRetryExhausted $exhausted): null => null],
);
```

Both `retryOnResponse` and `retryOnException` receive the `RequestInterface` as a
second argument, so retries can be gated on the request method:

```php
retryOnResponse: fn(ResponseInterface $r, RequestInterface $req): bool
    => $req->getMethod() === 'GET' && $r->getStatusCode() >= 500,
```

Ready-made response predicates:

| Predicate | Retries on |
|---|---|
| `RetryDecisions::serverErrors()` | Any 5xx (500–599). |
| `RetryDecisions::rateLimited()` | 429 only. |
| `RetryDecisions::transient()` | 408, 425, 429, 500, 502, 503, 504. |
| `RetryDecisions::onlyIdempotent($inner)` | Wraps `$inner`; retries **only** idempotent methods (GET, HEAD, PUT, DELETE, OPTIONS, TRACE). |

Constructor arguments beyond `inner` / `policy` / `retryOnResponse`:

| Argument | Default | Effect |
|---|---|---|
| `clock` | `Clock\SystemClock` | PSR-20 clock for `Retry-After` HTTP-date parsing and budget timing. |
| `respectRetryAfter` | `true` | Honor the server's `Retry-After` header. |
| `maxRetryAfterMs` | `300_000` | Cap on a `Retry-After` delay; `null` disables the cap. |
| `budgetMs` | `null` | Total wall-clock budget; a retry is skipped when `elapsed + delay` would exceed it. |
| `retryOnException` | `null` | Predicate `fn(ClientExceptionInterface, RequestInterface): bool`; `null` retries every transport exception. Non-matching exceptions are rethrown as-is. |
| `throwOnExhausted` | `false` | When `true`, throw `Http\HttpRetryExhausted` (carrying the history) on exhaustion instead of returning the last response / rethrowing the last transport exception. |
| `onRetry` | `[]` | Callbacks `fn(HttpAttemptRecord): void` fired before each retry sleep. |
| `onExhausted` | `[]` | Callbacks `fn(HttpRetryExhausted): void` fired on every exhaustion (`maxAttempts` or `budgetMs`); the argument carries `attempts` and the full `history`. |

A single attempt budget covers both retryable responses and PSR-18 transport
exceptions, so the inner client is called at most `maxAttempts` times. When a
retryable response carries a valid `Retry-After` header, that delay replaces the
configured backoff (delta-seconds or IMF-fixdate; the obsolete RFC 850 and
asctime date forms are ignored). The server delay carries no jitter and is capped
by `maxRetryAfterMs`. Transport exceptions always fall back to backoff (no
`Retry-After` is available). Pass `respectRetryAfter: false` to disable header
handling.

`Http\HttpRetryExhausted` implements `Psr\Http\Client\ClientExceptionInterface`,
so it stays within the PSR-18 contract — code that catches `ClientExceptionInterface`
still catches it.

Each `HttpAttemptRecord` carries `attempt`, `delayMs`, `elapsedMs`, and exactly
one of `response` / `exception` (never both, never neither). Inject a
`Clock\FakeClock` to make `Retry-After` delays and budget timing deterministic in
tests.

### Public API

| Class | Description |
|---|---|
| `Retry` | Immutable retry builder and closure runner. |
| `RetryPolicy` | Reusable policy object for decorators. |
| `RetryPolicyInterface` | Read-only policy contract. |
| `RetryExhausted` | Exception with attempts, last exception, history, and `reason`. |
| `ExhaustionReason` | Enum: `MaxAttempts` or `TimeBudget`. |
| `AttemptRecord` | One failed attempt: attempt number, `delayMs` (null when terminal), elapsed, and exception. |
| `UnacceptableResult` | Exception carrying the value a `retryIfResult()` predicate rejected. |
| `BackoffStrategy\BackoffStrategyInterface` | Backoff delay contract. |
| `BackoffStrategy\FixedBackoff` | Constant delay. |
| `BackoffStrategy\ExponentialBackoff` | Exponential delay with cap. |
| `BackoffStrategy\ImmediateBackoff` | Zero delay. |
| `Jitter\JitterInterface` | Jitter contract. |
| `Jitter\JitterMode` | Enum selecting a strategy for `jitter()`: `Additive`, `Full`, `None`. |
| `Jitter\FullJitter` | Random delay between zero and computed delay. |
| `Jitter\AdditiveJitter` | Equal jitter: spreads the delay downward, never above it. |
| `Jitter\NoJitter` | Leaves delay unchanged. |
| `Clock\SystemClock` | PSR-20 system clock. |
| `Clock\FakeClock` | Mutable PSR-20 clock for tests with `advanceMs()`. |
| `Sleeper\SleeperInterface` | Sleep contract. |
| `Sleeper\SystemSleeper` | `usleep()` implementation. |
| `Sleeper\FakeSleeper` | Test sleeper recording delays. |
| `Randomizer\RandomizerInterface` | Float randomizer contract. |
| `Randomizer\SystemRandomizer` | Runtime randomizer. |
| `Randomizer\FixedRandomizer` | Deterministic test randomizer. |
| `Http\RetryingHttpClient` | PSR-18 retrying decorator with `Retry-After` support. |
| `Http\RetryDecisions` | Ready-made response predicates + `onlyIdempotent()` wrapper. |
| `Http\HttpAttemptRecord` | One HTTP attempt: attempt, delay, elapsed, and `response` xor `exception`. |
| `Http\HttpRetryExhausted` | Exhaustion descriptor passed to every `onExhausted` hook and thrown when `throwOnExhausted` is set; a `ClientExceptionInterface` carrying `attempts` and the history. |
| `Http\RetryAfterParser` | Parses `Retry-After` into milliseconds via PSR-20 clock. |

## Long-running workers (RoadRunner, Swoole, FrankenPHP)

The package is safe to reuse across requests in a long-lived worker: every class
is `final readonly` with no global or static mutable state, and `SystemClock`
re-reads the wall clock on each `now()` call (it never freezes). Build a `Retry`
or `RetryingHttpClient` once and share it.

The caveat is the **backoff sleep**. `SystemSleeper::sleepMs()` calls `usleep()`,
which **blocks the current worker** for the whole delay. A worker serves one
request at a time, so a retry that waits — exponential backoff up to `capMs`
(default 30s), or a server `Retry-After` up to `maxRetryAfterMs` (**default
300_000 = 5 min**) — ties that worker up for the duration. With a fixed worker
pool, a handful of requests retrying with long delays can starve the pool and
collapse throughput.

Recommendations:

| Lever | Action |
|---|---|
| Cap server-driven delays | Lower `maxRetryAfterMs` (e.g. a few seconds) so a hostile/large `Retry-After` cannot pin a worker; keep `capMs` and `budgetMs` modest. |
| Move long retries off the hot path | Run retries with meaningful backoff from a queue / RoadRunner Jobs worker, not the synchronous request worker. |
| Cooperative sleep | Inject a non-blocking `SleeperInterface` via `Retry::withSleeper()` (core) or the policy's sleeper (HTTP) when your runtime offers cooperative scheduling (e.g. a Swoole coroutine sleep). |

`RetryingHttpClient` decorates your **outgoing** PSR-18 client; it is unrelated to
the server's inbound PSR-7/PSR-15 request handling.

## Security

This package only calls closures and PSR-18 clients supplied by the application.
It does not inspect credentials, URLs, request bodies, or response bodies. Hooks
receive exceptions and timing metadata; do not log secrets from exception messages
without application-level redaction. `Retry-After` values are treated as opaque
timing hints and are not used to construct URLs or queries.

**Idempotency.** `RetryingHttpClient` will retry whatever request you give it,
including non-idempotent methods (`POST`, `PATCH`), which can cause duplicate
side effects if the server processed the first request before failing the
response. Gate retries to idempotent methods with
`RetryDecisions::onlyIdempotent(...)` or your own request-aware predicate, unless
the endpoint is safe to repeat (e.g. protected by an idempotency key).

## Examples

See [examples/](examples/) for runnable scripts.

| Script | Shows | Needs server? |
|---|---|---|
| `basic.php` | Closure retry with fake sleeper and fixed backoff | No |
| `time_budget.php` | `stopAfterMs` with FakeClock + elapsed in history | No |
| `result_retry.php` | `retryIfResult` on a returned value; `UnacceptableResult` on exhaustion | No |
| `retry_after.php` | PSR-18 decorator: `RetryDecisions`, capped `Retry-After`, `onRetry` hook | No |

## Development

No PHP/Composer on the host. Run commands in Docker via the `composer:2` image:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer install
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```

Or with Make:

```bash
make install
make build
make cs-fix
make test
make test-coverage
make mutation
make release-check
```

## License

[BSD-3-Clause](LICENSE.md)
