<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Tests\Support;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Records every log call for assertions. PSR-3 forbids throwing from log(),
 * so this spy never fails on its own.
 */
final class SpyLogger implements LoggerInterface
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function emergency(string|\Stringable $message, array $context = []): void
    {
        $this->log(level: LogLevel::EMERGENCY, message: $message, context: $context);
    }

    public function alert(string|\Stringable $message, array $context = []): void
    {
        $this->log(level: LogLevel::ALERT, message: $message, context: $context);
    }

    public function critical(string|\Stringable $message, array $context = []): void
    {
        $this->log(level: LogLevel::CRITICAL, message: $message, context: $context);
    }

    public function error(string|\Stringable $message, array $context = []): void
    {
        $this->log(level: LogLevel::ERROR, message: $message, context: $context);
    }

    public function warning(string|\Stringable $message, array $context = []): void
    {
        $this->log(level: LogLevel::WARNING, message: $message, context: $context);
    }

    public function notice(string|\Stringable $message, array $context = []): void
    {
        $this->log(level: LogLevel::NOTICE, message: $message, context: $context);
    }

    public function info(string|\Stringable $message, array $context = []): void
    {
        $this->log(level: LogLevel::INFO, message: $message, context: $context);
    }

    public function debug(string|\Stringable $message, array $context = []): void
    {
        $this->log(level: LogLevel::DEBUG, message: $message, context: $context);
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
