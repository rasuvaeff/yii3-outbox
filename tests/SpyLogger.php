<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox\Tests;

use Psr\Log\LoggerInterface;

final class SpyLogger implements LoggerInterface
{
    public bool $warningCalled = false;
    public array $warningContext = [];

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
        $this->warningCalled = true;
        $this->warningContext = $context;
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
