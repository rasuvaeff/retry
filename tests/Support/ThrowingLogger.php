<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Tests\Support;

use Psr\Log\LoggerInterface;

/**
 * PSR-3-violating logger that throws on every call — proves RetryLog treats
 * logging as best-effort. Signatures stay untyped for psr/log ^1 (see SpyLogger).
 */
final class ThrowingLogger implements LoggerInterface
{
    public int $calls = 0;

    #[\Override]
    public function emergency($message, array $context = []): void
    {
        $this->boom();
    }

    #[\Override]
    public function alert($message, array $context = []): void
    {
        $this->boom();
    }

    #[\Override]
    public function critical($message, array $context = []): void
    {
        $this->boom();
    }

    #[\Override]
    public function error($message, array $context = []): void
    {
        $this->boom();
    }

    #[\Override]
    public function warning($message, array $context = []): void
    {
        $this->boom();
    }

    #[\Override]
    public function notice($message, array $context = []): void
    {
        $this->boom();
    }

    #[\Override]
    public function info($message, array $context = []): void
    {
        $this->boom();
    }

    #[\Override]
    public function debug($message, array $context = []): void
    {
        $this->boom();
    }

    #[\Override]
    public function log($level, $message, array $context = []): void
    {
        $this->boom();
    }

    private function boom(): never
    {
        $this->calls++;

        throw new \RuntimeException(message: 'logger is broken');
    }
}
