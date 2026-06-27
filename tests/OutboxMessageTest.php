<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\OutboxStatus;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(OutboxMessage::class)]
final class OutboxMessageTest
{
    private OutboxMessage $fixture;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->fixture = new OutboxMessage(
            id: 'test-id',
            type: 'order.created',
            payload: '{"orderId": 1}',
            status: OutboxStatus::Pending,
            createdAt: new DateTimeImmutable('2026-01-01 00:00:00'),
        );
    }

    public function createsWithAllProperties(): void
    {
        Assert::same($this->fixture->getId(), 'test-id');
        Assert::same($this->fixture->getType(), 'order.created');
        Assert::same($this->fixture->getPayload(), '{"orderId": 1}');
        Assert::same($this->fixture->getStatus(), OutboxStatus::Pending);
        Assert::same($this->fixture->getCreatedAt()->format('Y-m-d H:i:s'), '2026-01-01 00:00:00');
        Assert::same($this->fixture->getAttempts(), 0);
        Assert::null($this->fixture->getLastAttemptAt());
        Assert::null($this->fixture->getAggregateId());
    }

    public function createsViaFactoryMethod(): void
    {
        $message = OutboxMessage::create(
            type: 'user.registered',
            payload: '{"userId": 42}',
        );

        Assert::same($message->getType(), 'user.registered');
        Assert::same($message->getPayload(), '{"userId": 42}');
        Assert::same($message->getStatus(), OutboxStatus::Pending);
        Assert::same($message->getAttempts(), 0);
        Assert::null($message->getAggregateId());
        Assert::true(preg_match('/^[0-9a-f]{32}$/', $message->getId()) === 1);
    }

    public function createsWithAggregateId(): void
    {
        $message = OutboxMessage::create(
            type: 'order.created',
            payload: '{}',
            aggregateId: 'order-42',
        );

        Assert::same($message->getAggregateId(), 'order-42');
    }

    public function createsWithExplicitCreatedAt(): void
    {
        $at = new DateTimeImmutable('2026-06-01 10:00:00');

        $message = OutboxMessage::create(
            type: 'test',
            payload: '{}',
            createdAt: $at,
        );

        Assert::same($message->getCreatedAt()->format('Y-m-d H:i:s'), '2026-06-01 10:00:00');
    }

    public function withStatusReturnsNewInstance(): void
    {
        $published = $this->fixture->withStatus(OutboxStatus::Published);

        Assert::same($published->getStatus(), OutboxStatus::Published);
        Assert::same($this->fixture->getStatus(), OutboxStatus::Pending);
        Assert::same($published->getId(), $this->fixture->getId());
    }

    public function withStatusPreservesAggregateId(): void
    {
        $message = OutboxMessage::create(type: 'test', payload: '{}', aggregateId: 'agg-1');
        $updated = $message->withStatus(OutboxStatus::Published);

        Assert::same($updated->getAggregateId(), 'agg-1');
    }

    public function withAttemptIncrementsAttemptsAndSetsTimestamp(): void
    {
        $at = new DateTimeImmutable('2026-06-01 12:00:00');
        $attempted = $this->fixture->withAttempt($at);

        Assert::same($attempted->getAttempts(), 1);
        Assert::same($this->fixture->getAttempts(), 0);
        Assert::same($attempted->getLastAttemptAt()?->format('Y-m-d H:i:s'), '2026-06-01 12:00:00');
    }

    public function withAttemptPreservesAggregateId(): void
    {
        $message = OutboxMessage::create(type: 'test', payload: '{}', aggregateId: 'agg-1');
        $attempted = $message->withAttempt(new DateTimeImmutable());

        Assert::same($attempted->getAggregateId(), 'agg-1');
    }

    public function throwsOnEmptyId(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new OutboxMessage(
            id: '',
            type: 'test',
            payload: '{}',
            status: OutboxStatus::Pending,
            createdAt: new DateTimeImmutable(),
        );
    }

    public function throwsOnEmptyType(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new OutboxMessage(
            id: 'id',
            type: '',
            payload: '{}',
            status: OutboxStatus::Pending,
            createdAt: new DateTimeImmutable(),
        );
    }

    public function throwsOnNegativeAttempts(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new OutboxMessage(
            id: 'id',
            type: 'test',
            payload: '{}',
            status: OutboxStatus::Pending,
            createdAt: new DateTimeImmutable(),
            attempts: -1,
        );
    }
}
