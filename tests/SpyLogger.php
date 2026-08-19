<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox\Tests;

use Psr\Log\LoggerInterface;

final class SpyLogger implements LoggerInterface
{
    public bool $warningCalled = false;
    public array $warningContext = [];

    /**
     * @var list<array{level: string, message: string, context: array<string, mixed>}>
     */
    public array $records = [];

    #[\Override]
    public function emergency(\Stringable|string $message, array $context = []): void {}

    #[\Override]
    public function alert(\Stringable|string $message, array $context = []): void {}

    #[\Override]
    public function critical(\Stringable|string $message, array $context = []): void {}

    #[\Override]
    public function error(\Stringable|string $message, array $context = []): void
    {
        $this->records[] = ['level' => 'error', 'message' => (string) $message, 'context' => $context];
    }

    #[\Override]
    public function warning(\Stringable|string $message, array $context = []): void
    {
        $this->warningCalled = true;
        $this->warningContext = $context;
        $this->records[] = ['level' => 'warning', 'message' => (string) $message, 'context' => $context];
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
