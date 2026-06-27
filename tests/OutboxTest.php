<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox\Tests;

use DateTimeImmutable;
use Rasuvaeff\Yii3Outbox\InMemoryStorage;
use Rasuvaeff\Yii3Outbox\Outbox;
use Rasuvaeff\Yii3Outbox\OutboxStatus;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(Outbox::class)]
final class OutboxTest
{
    private InMemoryStorage $storage;
    private StubClock $clock;
    private Outbox $outbox;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->storage = new InMemoryStorage();
        $this->clock = new StubClock(new DateTimeImmutable('2026-06-01 10:00:00'));
        $this->outbox = new Outbox(
            storage: $this->storage,
            clock: $this->clock,
        );
    }

    public function recordSavesMessageAndReturnsIt(): void
    {
        $message = $this->outbox->record(
            type: 'order.created',
            payload: '{"orderId": 1}',
        );

        Assert::same($message->getType(), 'order.created');
        Assert::same($message->getPayload(), '{"orderId": 1}');
        Assert::same($message->getStatus(), OutboxStatus::Pending);
        Assert::same($message->getAttempts(), 0);
        Assert::null($message->getAggregateId());
    }

    public function recordUsesClockForCreatedAt(): void
    {
        $message = $this->outbox->record(type: 'test', payload: '{}');

        Assert::same(
            $message->getCreatedAt()->format('Y-m-d H:i:s'),
            '2026-06-01 10:00:00',
        );
    }

    public function recordSetsAggregateId(): void
    {
        $message = $this->outbox->record(
            type: 'order.created',
            payload: '{}',
            aggregateId: 'order-42',
        );

        Assert::same($message->getAggregateId(), 'order-42');
    }

    public function recordPersistsMessageInStorage(): void
    {
        $message = $this->outbox->record(type: 'test', payload: '{}');

        $retrieved = $this->storage->getById($message->getId());

        Assert::notNull($retrieved);
        Assert::same($retrieved->getId(), $message->getId());
    }

    public function recordGeneratesUniqueIds(): void
    {
        $first = $this->outbox->record(type: 'test', payload: '{}');
        $second = $this->outbox->record(type: 'test', payload: '{}');

        Assert::notSame($first->getId(), $second->getId());
    }
}
