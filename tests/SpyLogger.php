<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingClickHouse\Tests;

use Psr\Log\LoggerInterface;

/**
 * @internal
 */
final class SpyLogger implements LoggerInterface
{
    /** @var list<array{message: string, context: array<string, mixed>}> */
    public array $warnings = [];

    #[\Override]
    public function emergency(\Stringable|string $message, array $context = []): void {}

    #[\Override]
    public function alert(\Stringable|string $message, array $context = []): void {}

    #[\Override]
    public function critical(\Stringable|string $message, array $context = []): void {}

    #[\Override]
    public function error(\Stringable|string $message, array $context = []): void {}

    #[\Override]
    public function warning(\Stringable|string $message, array $context = []): void
    {
        $this->warnings[] = [
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    #[\Override]
    public function notice(\Stringable|string $message, array $context = []): void {}

    #[\Override]
    public function info(\Stringable|string $message, array $context = []): void {}

    #[\Override]
    public function debug(\Stringable|string $message, array $context = []): void {}

    #[\Override]
    public function log($level, \Stringable|string $message, array $context = []): void {}
}
