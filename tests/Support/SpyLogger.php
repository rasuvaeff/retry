<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Tests\Support;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Records every log call for assertions. PSR-3 forbids throwing from log(),
 * so this spy never fails on its own.
 *
 * Method signatures stay untyped where psr/log v1 declares no type: narrowing
 * them to `string|Stringable` would make the class declaration fail when the
 * prefer-lowest job resolves psr/log ^1.
 */
final class SpyLogger implements LoggerInterface
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    #[\Override]
    public function emergency($message, array $context = []): void
    {
        $this->log(level: LogLevel::EMERGENCY, message: $message, context: $context);
    }

    #[\Override]
    public function alert($message, array $context = []): void
    {
        $this->log(level: LogLevel::ALERT, message: $message, context: $context);
    }

    #[\Override]
    public function critical($message, array $context = []): void
    {
        $this->log(level: LogLevel::CRITICAL, message: $message, context: $context);
    }

    #[\Override]
    public function error($message, array $context = []): void
    {
        $this->log(level: LogLevel::ERROR, message: $message, context: $context);
    }

    #[\Override]
    public function warning($message, array $context = []): void
    {
        $this->log(level: LogLevel::WARNING, message: $message, context: $context);
    }

    #[\Override]
    public function notice($message, array $context = []): void
    {
        $this->log(level: LogLevel::NOTICE, message: $message, context: $context);
    }

    #[\Override]
    public function info($message, array $context = []): void
    {
        $this->log(level: LogLevel::INFO, message: $message, context: $context);
    }

    #[\Override]
    public function debug($message, array $context = []): void
    {
        $this->log(level: LogLevel::DEBUG, message: $message, context: $context);
    }

    #[\Override]
    public function log($level, $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
