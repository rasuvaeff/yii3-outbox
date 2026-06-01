<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\OutboxStatus;

#[CoversClass(OutboxMessage::class)]
final class OutboxMessageTest extends TestCase
{
    private OutboxMessage $fixture;

    #[\Override]
    protected function setUp(): void
    {
        $this->fixture = new OutboxMessage(
            id: 'test-id',
            type: 'order.created',
            payload: '{"orderId": 1}',
            status: OutboxStatus::Pending,
            createdAt: new DateTimeImmutable('2026-01-01 00:00:00'),
        );
    }

    #[Test]
    public function createsWithAllProperties(): void
    {
        $this->assertSame('test-id', $this->fixture->getId());
        $this->assertSame('order.created', $this->fixture->getType());
        $this->assertSame('{"orderId": 1}', $this->fixture->getPayload());
        $this->assertSame(OutboxStatus::Pending, $this->fixture->getStatus());
        $this->assertSame('2026-01-01 00:00:00', $this->fixture->getCreatedAt()->format('Y-m-d H:i:s'));
        $this->assertSame(0, $this->fixture->getAttempts());
        $this->assertNull($this->fixture->getLastAttemptAt());
        $this->assertNull($this->fixture->getAggregateId());
    }

    #[Test]
    public function createsViaFactoryMethod(): void
    {
        $message = OutboxMessage::create(
            type: 'user.registered',
            payload: '{"userId": 42}',
        );

        $this->assertSame('user.registered', $message->getType());
        $this->assertSame('{"userId": 42}', $message->getPayload());
        $this->assertSame(OutboxStatus::Pending, $message->getStatus());
        $this->assertSame(0, $message->getAttempts());
        $this->assertNull($message->getAggregateId());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $message->getId());
    }

    #[Test]
    public function createsWithAggregateId(): void
    {
        $message = OutboxMessage::create(
            type: 'order.created',
            payload: '{}',
            aggregateId: 'order-42',
        );

        $this->assertSame('order-42', $message->getAggregateId());
    }

    #[Test]
    public function createsWithExplicitCreatedAt(): void
    {
        $at = new DateTimeImmutable('2026-06-01 10:00:00');

        $message = OutboxMessage::create(
            type: 'test',
            payload: '{}',
            createdAt: $at,
        );

        $this->assertSame('2026-06-01 10:00:00', $message->getCreatedAt()->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function withStatusReturnsNewInstance(): void
    {
        $published = $this->fixture->withStatus(OutboxStatus::Published);

        $this->assertSame(OutboxStatus::Published, $published->getStatus());
        $this->assertSame(OutboxStatus::Pending, $this->fixture->getStatus());
        $this->assertSame($this->fixture->getId(), $published->getId());
    }

    #[Test]
    public function withStatusPreservesAggregateId(): void
    {
        $message = OutboxMessage::create(type: 'test', payload: '{}', aggregateId: 'agg-1');
        $updated = $message->withStatus(OutboxStatus::Published);

        $this->assertSame('agg-1', $updated->getAggregateId());
    }

    #[Test]
    public function withAttemptIncrementsAttemptsAndSetsTimestamp(): void
    {
        $at = new DateTimeImmutable('2026-06-01 12:00:00');
        $attempted = $this->fixture->withAttempt($at);

        $this->assertSame(1, $attempted->getAttempts());
        $this->assertSame(0, $this->fixture->getAttempts());
        $this->assertSame('2026-06-01 12:00:00', $attempted->getLastAttemptAt()?->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function withAttemptPreservesAggregateId(): void
    {
        $message = OutboxMessage::create(type: 'test', payload: '{}', aggregateId: 'agg-1');
        $attempted = $message->withAttempt(new DateTimeImmutable());

        $this->assertSame('agg-1', $attempted->getAggregateId());
    }

    #[Test]
    public function throwsOnEmptyId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Message id must not be empty');

        new OutboxMessage(
            id: '',
            type: 'test',
            payload: '{}',
            status: OutboxStatus::Pending,
            createdAt: new DateTimeImmutable(),
        );
    }

    #[Test]
    public function throwsOnEmptyType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Message type must not be empty');

        new OutboxMessage(
            id: 'id',
            type: '',
            payload: '{}',
            status: OutboxStatus::Pending,
            createdAt: new DateTimeImmutable(),
        );
    }

    #[Test]
    public function throwsOnNegativeAttempts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Attempts must be non-negative');

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
