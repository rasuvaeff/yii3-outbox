<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox\Tests;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Rasuvaeff\Understudy\Understudy;
use Rasuvaeff\Yii3Outbox\InMemoryStorage;
use Rasuvaeff\Yii3Outbox\MessageIdGeneratorInterface;
use Rasuvaeff\Yii3Outbox\Outbox;
use Rasuvaeff\Yii3Outbox\OutboxStatus;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

use function Rasuvaeff\Understudy\verify;
use function Rasuvaeff\Understudy\when;

#[Test]
#[Covers(Outbox::class)]
final class OutboxTest
{
    private InMemoryStorage $storage;
    private ClockInterface $clock;
    private Outbox $outbox;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->storage = new InMemoryStorage();
        $this->clock = $this->fixedClock('2026-06-01 10:00:00');
        $this->outbox = new Outbox(
            storage: $this->storage,
            clock: $this->clock,
        );
    }

    private function fixedClock(string $now): ClockInterface
    {
        $clock = Understudy::for(ClockInterface::class);
        when(fn() => $clock->now())->returns(new DateTimeImmutable($now));

        return $clock;
    }

    private function idGenerator(string $id): MessageIdGeneratorInterface
    {
        $generator = Understudy::for(MessageIdGeneratorInterface::class);
        when(fn() => $generator->generate())->returns($id);

        return $generator;
    }

    public function defaultIdFormatIsUnchanged(): void
    {
        $message = $this->outbox->record(type: 'order.created', payload: '{}');

        Assert::same(preg_match('/^[0-9a-f]{32}$/', $message->getId()), 1);
    }

    public function idComesFromTheConfiguredGenerator(): void
    {
        $outbox = new Outbox(
            storage: $this->storage,
            clock: $this->clock,
            idGenerator: $this->idGenerator('generated-id'),
        );

        Assert::same($outbox->record(type: 'order.created', payload: '{}')->getId(), 'generated-id');
    }

    public function domainEventIdIsUsedAsIsAndSkipsTheGenerator(): void
    {
        // republishing the same domain event must not mint a second id, or the
        // receiver has nothing stable to deduplicate on
        $generator = $this->idGenerator('generated-id');
        $outbox = new Outbox(storage: $this->storage, clock: $this->clock, idGenerator: $generator);

        $message = $outbox->record(type: 'order.created', payload: '{}', id: 'order-created-42');

        Assert::same($message->getId(), 'order-created-42');
        verify(fn() => $generator->generate(), never: true);
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
