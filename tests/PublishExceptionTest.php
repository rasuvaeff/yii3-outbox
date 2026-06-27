<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox\Tests;

use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\PublishException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(PublishException::class)]
final class PublishExceptionTest
{
    public function holdsOutboxMessage(): void
    {
        $message = OutboxMessage::create(type: 'test', payload: '{}');
        $exception = new PublishException(
            message: 'Failed',
            outboxMessage: $message,
        );

        Assert::same($exception->getMessage(), 'Failed');
        Assert::same($exception->getOutboxMessage(), $message);
    }

    public function preservesPreviousException(): void
    {
        $previous = new \RuntimeException('connection refused');
        $message = OutboxMessage::create(type: 'test', payload: '{}');
        $exception = new PublishException(
            message: 'Failed',
            outboxMessage: $message,
            previous: $previous,
        );

        Assert::same($exception->getPrevious(), $previous);
    }

    public function preservesErrorCode(): void
    {
        $message = OutboxMessage::create(type: 'test', payload: '{}');
        $exception = new PublishException(
            message: 'Failed',
            outboxMessage: $message,
            code: 42,
        );

        Assert::same($exception->getCode(), 42);
    }

    public function defaultsToErrorCodeZero(): void
    {
        $exception = new PublishException(
            message: 'Failed',
            outboxMessage: OutboxMessage::create(type: 'test', payload: '{}'),
        );

        Assert::same($exception->getCode(), 0);
    }
}
