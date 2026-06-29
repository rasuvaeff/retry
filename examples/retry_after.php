<?php

declare(strict_types=1);

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Rasuvaeff\Retry\BackoffStrategy\FixedBackoff;
use Rasuvaeff\Retry\Clock\FakeClock;
use Rasuvaeff\Retry\Http\HttpAttemptRecord;
use Rasuvaeff\Retry\Http\RetryDecisions;
use Rasuvaeff\Retry\Http\RetryingHttpClient;
use Rasuvaeff\Retry\Jitter\NoJitter;
use Rasuvaeff\Retry\Randomizer\SystemRandomizer;
use Rasuvaeff\Retry\RetryPolicy;
use Rasuvaeff\Retry\Sleeper\FakeSleeper;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Minimal PSR-7 response stub for the example. Real code uses Guzzle/Symfony/Nyholm messages.
 */
final class ExampleResponse implements ResponseInterface
{
    public function __construct(
        private readonly int $statusCode,
        private readonly array $headers = [],
    ) {}

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getHeaderLine(string $name): string
    {
        return $this->headers[$name] ?? '';
    }

    public function withStatus(int $code, string $reasonPhrase = ''): ResponseInterface
    {
        return $this;
    }

    public function getReasonPhrase(): string
    {
        return '';
    }

    public function getProtocolVersion(): string
    {
        return '1.1';
    }

    public function withProtocolVersion(string $version): ResponseInterface
    {
        return $this;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[$name]);
    }

    public function getHeader(string $name): array
    {
        return isset($this->headers[$name]) ? [$this->headers[$name]] : [];
    }

    public function withHeader(string $name, $value): ResponseInterface
    {
        return $this;
    }

    public function withAddedHeader(string $name, $value): ResponseInterface
    {
        return $this;
    }

    public function withoutHeader(string $name): ResponseInterface
    {
        return $this;
    }

    public function getBody(): StreamInterface
    {
        throw new LogicException(message: 'Body is not used by this example');
    }

    public function withBody(StreamInterface $body): ResponseInterface
    {
        return $this;
    }
}

/**
 * Minimal PSR-7 request stub. Real code uses your framework's request object.
 */
final class ExampleRequest implements RequestInterface
{
    public function getRequestTarget(): string
    {
        return '/';
    }

    public function withRequestTarget(string $requestTarget): RequestInterface
    {
        return $this;
    }

    public function getMethod(): string
    {
        return 'GET';
    }

    public function withMethod(string $method): RequestInterface
    {
        return $this;
    }

    public function getUri(): UriInterface
    {
        throw new LogicException(message: 'URI is not used by this example');
    }

    public function withUri(UriInterface $uri, bool $preserveHost = false): RequestInterface
    {
        return $this;
    }

    public function getProtocolVersion(): string
    {
        return '1.1';
    }

    public function withProtocolVersion(string $version): RequestInterface
    {
        return $this;
    }

    public function getHeaders(): array
    {
        return [];
    }

    public function hasHeader(string $name): bool
    {
        return false;
    }

    public function getHeader(string $name): array
    {
        return [];
    }

    public function getHeaderLine(string $name): string
    {
        return '';
    }

    public function withHeader(string $name, $value): RequestInterface
    {
        return $this;
    }

    public function withAddedHeader(string $name, $value): RequestInterface
    {
        return $this;
    }

    public function withoutHeader(string $name): RequestInterface
    {
        return $this;
    }

    public function getBody(): StreamInterface
    {
        throw new LogicException(message: 'Body is not used by this example');
    }

    public function withBody(StreamInterface $body): RequestInterface
    {
        return $this;
    }
}

/**
 * In production, pass your real PSR-18 client (Guzzle, Symfony, etc.) here.
 * This stub returns 503 + Retry-After: 1 on the first call, then 200.
 */
final class ExampleInnerClient implements ClientInterface
{
    public int $calls = 0;

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->calls++;

        if ($this->calls === 1) {
            return new ExampleResponse(statusCode: 503, headers: ['Retry-After' => '1']);
        }

        return new ExampleResponse(statusCode: 200);
    }
}

$sleeper = new FakeSleeper();
$inner = new ExampleInnerClient();

$client = new RetryingHttpClient(
    inner: $inner,
    policy: new RetryPolicy(
        maxAttempts: 3,
        backoff: new FixedBackoff(delayMs: 100),
        jitter: new NoJitter(),
        sleeper: $sleeper,
        randomizer: new SystemRandomizer(),
    ),
    retryOnResponse: RetryDecisions::onlyIdempotent(RetryDecisions::transient()),
    clock: new FakeClock(),
    maxRetryAfterMs: 500,
    onRetry: [
        static function (HttpAttemptRecord $record): void {
            printf(
                "retry: attempt=%d status=%d delay=%d ms\n",
                $record->attempt,
                $record->response?->getStatusCode() ?? 0,
                $record->delayMs ?? 0,
            );
        },
    ],
);

$response = $client->sendRequest(request: new ExampleRequest());

printf(
    "status=%d innerCalls=%d firstSleep=%d ms (server asked 1000 via Retry-After, capped to maxRetryAfterMs=500)\n",
    $response->getStatusCode(),
    $inner->calls,
    $sleeper->delays()[0] ?? 0,
);
