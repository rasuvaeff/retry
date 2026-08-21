<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Tests\Http;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

final readonly class FakeRequest implements RequestInterface
{
    public function __construct(
        private string $method = 'GET',
        private ?StreamInterface $body = null,
    ) {}

    #[\Override]
    public function getRequestTarget(): string
    {
        return '/';
    }

    #[\Override]
    public function withRequestTarget(string $requestTarget): RequestInterface
    {
        return $this;
    }

    #[\Override]
    public function getMethod(): string
    {
        return $this->method;
    }

    #[\Override]
    public function withMethod(string $method): RequestInterface
    {
        return $this;
    }

    #[\Override]
    public function getUri(): UriInterface
    {
        throw new \LogicException('URI is not used by this test double');
    }

    #[\Override]
    public function withUri(UriInterface $uri, bool $preserveHost = false): RequestInterface
    {
        return $this;
    }

    #[\Override]
    public function getProtocolVersion(): string
    {
        return '1.1';
    }

    #[\Override]
    public function withProtocolVersion(string $version): RequestInterface
    {
        return $this;
    }

    #[\Override]
    public function getHeaders(): array
    {
        return [];
    }

    #[\Override]
    public function hasHeader(string $name): bool
    {
        return false;
    }

    #[\Override]
    public function getHeader(string $name): array
    {
        return [];
    }

    #[\Override]
    public function getHeaderLine(string $name): string
    {
        return '';
    }

    #[\Override]
    public function withHeader(string $name, $value): RequestInterface
    {
        return $this;
    }

    #[\Override]
    public function withAddedHeader(string $name, $value): RequestInterface
    {
        return $this;
    }

    #[\Override]
    public function withoutHeader(string $name): RequestInterface
    {
        return $this;
    }

    #[\Override]
    public function getBody(): StreamInterface
    {
        // The decorator legitimately touches the body since the rewind fix:
        // it rewinds a seekable body before every re-send. Default to an
        // empty seekable stream for tests that don't care about the body.
        return $this->body ?? new FakeStream();
    }

    #[\Override]
    public function withBody(StreamInterface $body): RequestInterface
    {
        return $this;
    }
}
