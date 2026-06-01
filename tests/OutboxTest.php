<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\Yii3Outbox\InMemoryStorage;
use Rasuvaeff\Yii3Outbox\Outbox;
use Rasuvaeff\Yii3Outbox\OutboxStatus;

#[CoversClass(Outbox::class)]
final class OutboxTest extends TestCase
{
    private InMemoryStorage $storage;
    private StubClock $clock;
    private Outbox $outbox;

    #[\Override]
    protected function setUp(): void
    {
        $this->storage = new InMemoryStorage();
        $this->clock = new StubClock(new DateTimeImmutable('2026-06-01 10:00:00'));
        $this->outbox = new Outbox(
            storage: $this->storage,
            clock: $this->clock,
        );
    }

    #[Test]
    public function recordSavesMessageAndReturnsIt(): void
    {
        $message = $this->outbox->record(
            type: 'order.created',
            payload: '{"orderId": 1}',
        );

        $this->assertSame('order.created', $message->getType());
        $this->assertSame('{"orderId": 1}', $message->getPayload());
        $this->assertSame(OutboxStatus::Pending, $message->getStatus());
        $this->assertSame(0, $message->getAttempts());
        $this->assertNull($message->getAggregateId());
    }

    #[Test]
    public function recordUsesClockForCreatedAt(): void
    {
        $message = $this->outbox->record(type: 'test', payload: '{}');

        $this->assertSame(
            '2026-06-01 10:00:00',
            $message->getCreatedAt()->format('Y-m-d H:i:s'),
        );
    }

    #[Test]
    public function recordSetsAggregateId(): void
    {
        $message = $this->outbox->record(
            type: 'order.created',
            payload: '{}',
            aggregateId: 'order-42',
        );

        $this->assertSame('order-42', $message->getAggregateId());
    }

    #[Test]
    public function recordPersistsMessageInStorage(): void
    {
        $message = $this->outbox->record(type: 'test', payload: '{}');

        $retrieved = $this->storage->getById($message->getId());

        $this->assertNotNull($retrieved);
        $this->assertSame($message->getId(), $retrieved->getId());
    }

    #[Test]
    public function recordGeneratesUniqueIds(): void
    {
        $first = $this->outbox->record(type: 'test', payload: '{}');
        $second = $this->outbox->record(type: 'test', payload: '{}');

        $this->assertNotSame($first->getId(), $second->getId());
    }
}
