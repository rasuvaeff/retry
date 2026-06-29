<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Tests\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

final readonly class FakeResponse implements ResponseInterface
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private int $statusCode,
        private array $headers = [],
    ) {}

    #[\Override]
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    #[\Override]
    public function withStatus(int $code, string $reasonPhrase = ''): ResponseInterface
    {
        return new self(statusCode: $code, headers: $this->headers);
    }

    #[\Override]
    public function getReasonPhrase(): string
    {
        return '';
    }

    #[\Override]
    public function getProtocolVersion(): string
    {
        return '1.1';
    }

    #[\Override]
    public function withProtocolVersion(string $version): ResponseInterface
    {
        return $this;
    }

    #[\Override]
    public function getHeaders(): array
    {
        return $this->headers;
    }

    #[\Override]
    public function hasHeader(string $name): bool
    {
        return isset($this->headers[$name]);
    }

    #[\Override]
    public function getHeader(string $name): array
    {
        return isset($this->headers[$name]) ? [$this->headers[$name]] : [];
    }

    #[\Override]
    public function getHeaderLine(string $name): string
    {
        return $this->headers[$name] ?? '';
    }

    #[\Override]
    public function withHeader(string $name, $value): ResponseInterface
    {
        return $this;
    }

    #[\Override]
    public function withAddedHeader(string $name, $value): ResponseInterface
    {
        return $this;
    }

    #[\Override]
    public function withoutHeader(string $name): ResponseInterface
    {
        return $this;
    }

    #[\Override]
    public function getBody(): StreamInterface
    {
        throw new \LogicException('Body is not used by this test double');
    }

    #[\Override]
    public function withBody(StreamInterface $body): ResponseInterface
    {
        return $this;
    }
}
