<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Tests\Http;

use Psr\Http\Message\StreamInterface;

/**
 * In-memory PSR-7 stream double. Reading advances the position the way a
 * transport would; `rewinds()` counts explicit rewinds so tests can assert
 * the retry decorator replays the body before each re-send.
 */
final class FakeStream implements StreamInterface
{
    private int $position = 0;

    private int $rewinds = 0;

    public function __construct(
        private readonly string $contents = '',
        private readonly bool $seekable = true,
    ) {}

    public function rewinds(): int
    {
        return $this->rewinds;
    }

    /** Reads the remainder, as a byte-oriented transport would. */
    public function drain(): string
    {
        $remaining = substr($this->contents, $this->position);
        $this->position = \strlen($this->contents);

        return $remaining;
    }

    #[\Override]
    public function __toString(): string
    {
        return $this->contents;
    }

    #[\Override]
    public function close(): void {}

    #[\Override]
    public function detach()
    {
        return null;
    }

    #[\Override]
    public function getSize(): ?int
    {
        return \strlen($this->contents);
    }

    #[\Override]
    public function tell(): int
    {
        return $this->position;
    }

    #[\Override]
    public function eof(): bool
    {
        return $this->position >= \strlen($this->contents);
    }

    #[\Override]
    public function isSeekable(): bool
    {
        return $this->seekable;
    }

    #[\Override]
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        if (!$this->seekable) {
            throw new \RuntimeException('Stream is not seekable');
        }

        $this->position = $offset;
    }

    #[\Override]
    public function rewind(): void
    {
        if (!$this->seekable) {
            throw new \RuntimeException('Stream is not seekable');
        }

        $this->position = 0;
        $this->rewinds++;
    }

    #[\Override]
    public function isWritable(): bool
    {
        return false;
    }

    #[\Override]
    public function write(string $string): int
    {
        throw new \RuntimeException('Stream is read-only');
    }

    #[\Override]
    public function isReadable(): bool
    {
        return true;
    }

    #[\Override]
    public function read(int $length): string
    {
        $chunk = substr($this->contents, $this->position, $length);
        $this->position += \strlen($chunk);

        return $chunk;
    }

    #[\Override]
    public function getContents(): string
    {
        return $this->drain();
    }

    #[\Override]
    public function getMetadata(?string $key = null)
    {
        return null;
    }
}
