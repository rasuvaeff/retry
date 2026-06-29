# Examples

Run examples from the package root after installing dependencies:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/basic.php
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/time_budget.php
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/result_retry.php
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/retry_after.php
```

| Script | Shows | Needs server? |
|---|---|---|
| `basic.php` | Closure retry with fake sleeper and fixed backoff | No |
| `time_budget.php` | `stopAfterMs` capping retry time; `elapsedMs` in history | No |
| `result_retry.php` | `retryIfResult` on a returned value; `UnacceptableResult` on exhaustion | No |
| `retry_after.php` | PSR-18 decorator: `onlyIdempotent`, `Retry-After` capped by `maxRetryAfterMs`, `onRetry` hook | No |
