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

    public function isNotTerminalByDefault(): void
    {
        $exception = new PublishException(
            message: 'Failed',
            outboxMessage: OutboxMessage::create(type: 'test', payload: '{}'),
        );

        Assert::false($exception->isTerminal());
    }

    public function terminalFactoryMarksTheFailureTerminal(): void
    {
        $previous = new \RuntimeException('410 Gone');
        $message = OutboxMessage::create(type: 'test', payload: '{}');

        $exception = PublishException::terminal(
            message: 'Endpoint is gone',
            outboxMessage: $message,
            code: 410,
            previous: $previous,
        );

        Assert::true($exception->isTerminal());
        Assert::same($exception->getMessage(), 'Endpoint is gone');
        Assert::same($exception->getOutboxMessage(), $message);
        Assert::same($exception->getCode(), 410);
        Assert::same($exception->getPrevious(), $previous);
    }

    public function terminalFactoryDefaultsCodeAndPrevious(): void
    {
        $exception = PublishException::terminal(
            message: 'Endpoint is gone',
            outboxMessage: OutboxMessage::create(type: 'test', payload: '{}'),
        );

        Assert::same($exception->getCode(), 0);
        Assert::null($exception->getPrevious());
    }

    public function constructorAcceptsTheTerminalFlag(): void
    {
        $exception = new PublishException(
            message: 'Failed',
            outboxMessage: OutboxMessage::create(type: 'test', payload: '{}'),
            terminal: true,
        );

        Assert::true($exception->isTerminal());
    }
}
