<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\PublishException;

#[CoversClass(PublishException::class)]
final class PublishExceptionTest extends TestCase
{
    #[Test]
    public function holdsOutboxMessage(): void
    {
        $message = OutboxMessage::create(type: 'test', payload: '{}');
        $exception = new PublishException(
            message: 'Failed',
            outboxMessage: $message,
        );

        $this->assertSame('Failed', $exception->getMessage());
        $this->assertSame($message, $exception->getOutboxMessage());
    }

    #[Test]
    public function preservesPreviousException(): void
    {
        $previous = new \RuntimeException('connection refused');
        $message = OutboxMessage::create(type: 'test', payload: '{}');
        $exception = new PublishException(
            message: 'Failed',
            outboxMessage: $message,
            previous: $previous,
        );

        $this->assertSame($previous, $exception->getPrevious());
    }

    #[Test]
    public function preservesErrorCode(): void
    {
        $message = OutboxMessage::create(type: 'test', payload: '{}');
        $exception = new PublishException(
            message: 'Failed',
            outboxMessage: $message,
            code: 42,
        );

        $this->assertSame(42, $exception->getCode());
    }

    #[Test]
    public function defaultsToErrorCodeZero(): void
    {
        $exception = new PublishException(
            message: 'Failed',
            outboxMessage: OutboxMessage::create(type: 'test', payload: '{}'),
        );

        $this->assertSame(0, $exception->getCode());
    }
}
