<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox\Tests;

use DateTimeImmutable;
use LogicException;
use Psr\Clock\ClockInterface;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Understudy;
use Rasuvaeff\Yii3Outbox\BatchSavingStorageInterface;
use Rasuvaeff\Yii3Outbox\InMemoryStorage;
use Rasuvaeff\Yii3Outbox\MessageIdGeneratorInterface;
use Rasuvaeff\Yii3Outbox\Outbox;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\OutboxMessageDraft;
use Rasuvaeff\Yii3Outbox\OutboxStatus;
use Rasuvaeff\Yii3Outbox\RequeueableStorageInterface;
use Rasuvaeff\Yii3Outbox\StorageInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
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

    /**
     * @param list<string> $ids
     */
    private function sequentialIdGenerator(array $ids): MessageIdGeneratorInterface
    {
        $generator = Understudy::for(MessageIdGeneratorInterface::class);
        $queue = $ids;
        when(fn() => $generator->generate())->answers(static function () use (&$queue): string {
            $next = array_shift($queue);
            \assert(is_string($next), 'generator asked for more ids than the test provided');

            return $next;
        });

        return $generator;
    }

    public function recordManyOfNothingTouchesNeitherStorageNorClock(): void
    {
        $storage = Understudy::for(BatchSavingStorageInterface::class);
        $clock = Understudy::for(ClockInterface::class);
        $outbox = new Outbox(storage: $storage, clock: $clock);

        Assert::same($outbox->recordMany([]), []);
        verify(fn() => $storage->saveBatch(Arg::any()), never: true);
        verify(fn() => $storage->save(Arg::any()), never: true);
        verify(fn() => $clock->now(), never: true);
    }

    public function recordManySavesTheBatchInOneWriteWhenTheStorageCan(): void
    {
        $storage = Understudy::delegate(BatchSavingStorageInterface::class, $this->storage);
        $outbox = new Outbox(storage: $storage, clock: $this->clock, idGenerator: $this->sequentialIdGenerator(['id-1', 'id-2']));

        $messages = $outbox->recordMany([
            new OutboxMessageDraft(type: 'order.created', payload: '{"orderId": 1}', aggregateId: 'order-1'),
            new OutboxMessageDraft(type: 'order.paid', payload: '{"orderId": 1}', id: 'paid-1'),
        ]);

        verify(fn() => $storage->saveBatch($messages), times: 1);
        verify(fn() => $storage->save(Arg::any()), never: true);
        Assert::same(array_map(static fn(OutboxMessage $m): string => $m->getId(), $messages), ['id-1', 'paid-1']);
        Assert::same($messages[0]->getType(), 'order.created');
        Assert::same($messages[0]->getAggregateId(), 'order-1');
        Assert::same($messages[1]->getType(), 'order.paid');
        Assert::same($messages[1]->getPayload(), '{"orderId": 1}');
        Assert::same($this->storage->getById('id-1'), $messages[0]);
        Assert::same($this->storage->getById('paid-1'), $messages[1]);
    }

    public function recordManyFallsBackToOneSavePerMessageOnAPlainStorage(): void
    {
        $storage = Understudy::delegate(StorageInterface::class, $this->storage);
        $outbox = new Outbox(storage: $storage, clock: $this->clock, idGenerator: $this->sequentialIdGenerator(['id-1', 'id-2']));

        $messages = $outbox->recordMany([
            new OutboxMessageDraft(type: 'a', payload: '{}'),
            new OutboxMessageDraft(type: 'b', payload: '{}'),
        ]);

        verify(fn() => $storage->save($messages[0]), times: 1);
        verify(fn() => $storage->save($messages[1]), times: 1);
        Assert::same($this->storage->getById('id-1')?->getType(), 'a');
        Assert::same($this->storage->getById('id-2')?->getType(), 'b');
    }

    public function recordManyReadsTheClockOnceForTheWholeBatch(): void
    {
        $clock = Understudy::for(ClockInterface::class);
        $ticks = [new DateTimeImmutable('2026-06-01 10:00:00'), new DateTimeImmutable('2026-06-01 10:00:01')];
        when(fn() => $clock->now())->answers(static function () use (&$ticks): DateTimeImmutable {
            $tick = array_shift($ticks);
            \assert($tick instanceof DateTimeImmutable);

            return $tick;
        });
        $outbox = new Outbox(storage: $this->storage, clock: $clock);

        $messages = $outbox->recordMany([
            new OutboxMessageDraft(type: 'a', payload: '{}'),
            new OutboxMessageDraft(type: 'b', payload: '{}'),
        ]);

        verify(fn() => $clock->now(), times: 1);
        Assert::same($messages[0]->getCreatedAt(), $messages[1]->getCreatedAt());
        Assert::same($messages[0]->getCreatedAt()->format('H:i:s'), '10:00:00');
    }

    public function recordManyStatusIsPendingWithNoAttempts(): void
    {
        [$message] = $this->outbox->recordMany([new OutboxMessageDraft(type: 'a', payload: '{}')]);

        Assert::same($message->getStatus(), OutboxStatus::Pending);
        Assert::same($message->getAttempts(), 0);
        Assert::null($message->getLastAttemptAt());
    }

    public function recordManyUsesTheGeneratorOnlyForDraftsWithoutAnId(): void
    {
        $generator = $this->sequentialIdGenerator(['generated']);
        $outbox = new Outbox(storage: $this->storage, clock: $this->clock, idGenerator: $generator);

        $messages = $outbox->recordMany([
            new OutboxMessageDraft(type: 'a', payload: '{}', id: 'given'),
            new OutboxMessageDraft(type: 'b', payload: '{}'),
        ]);

        verify(fn() => $generator->generate(), times: 1);
        Assert::same([$messages[0]->getId(), $messages[1]->getId()], ['given', 'generated']);
    }

    /**
     * recordMany() is record() applied to each draft, minus the extra clock
     * reads: the messages that end up in the storage are the same, field for
     * field, in the same order, and so are the ones returned.
     *
     * @param list<array{0: string, 1: string, 2: ?string, 3: bool}> $specs type, payload, aggregateId, has own id
     */
    #[Property(runs: 150)]
    public function recordManyIsEquivalentToRecordingEachDraft(array $specs, bool $batchCapable): void
    {
        Classify::cover($specs === [], 'empty batch', 5.0);
        Classify::cover(count($specs) > 1, 'several drafts', 40.0);

        $drafts = [];
        foreach ($specs as $index => [$type, $payload, $aggregateId, $hasOwnId]) {
            $drafts[] = new OutboxMessageDraft(type: $type, payload: $payload, aggregateId: $aggregateId, id: $hasOwnId ? 'given-' . $index : null);
        }
        $ids = array_map(static fn(int $i): string => 'gen-' . $i, range(1, max(1, count($drafts))));

        $viaMany = new InMemoryStorage();
        $manyOutbox = new Outbox(
            storage: $batchCapable ? $viaMany : Understudy::delegate(StorageInterface::class, $viaMany),
            clock: $this->clock,
            idGenerator: $this->sequentialIdGenerator($ids),
        );
        $viaOne = new InMemoryStorage();
        $oneOutbox = new Outbox(storage: $viaOne, clock: $this->clock, idGenerator: $this->sequentialIdGenerator($ids));

        $returned = $manyOutbox->recordMany($drafts);
        foreach ($drafts as $draft) {
            $oneOutbox->record(type: $draft->type, payload: $draft->payload, aggregateId: $draft->aggregateId, id: $draft->id);
        }

        $fields = static fn(OutboxMessage $m): array => [
            $m->getId(), $m->getType(), $m->getPayload(), $m->getStatus(), $m->getCreatedAt()->getTimestamp(),
            $m->getAttempts(), $m->getLastAttemptAt(), $m->getAggregateId(),
        ];

        Assert::same(array_map($fields, iterator_to_array($viaMany)), array_map($fields, iterator_to_array($viaOne)));
        Assert::same(array_map($fields, $returned), array_values(array_map($fields, iterator_to_array($viaOne))));
    }

    /** @return array<string, ArbitraryInterface> */
    public static function recordManyIsEquivalentToRecordingEachDraftGenerators(): array
    {
        $ident = Gen::stringFrom('abcdefghijklmnopqrstuvwxyz0123456789.-', minLength: 1, maxLength: 12);

        return [
            'specs' => Gen::arrayOf(Gen::tuple($ident, Gen::stringAscii(), Gen::nullable($ident), Gen::bool()), maxSize: 6),
            'batchCapable' => Gen::bool(),
        ];
    }

    public function requeueFailedThrowsOnAStorageThatCannotRequeue(): void
    {
        $outbox = new Outbox(storage: Understudy::for(StorageInterface::class), clock: $this->clock);

        Expect::exception(LogicException::class)->withMessageContaining('cannot requeue');

        $outbox->requeueFailed();
    }

    public function requeueFailedMovesEveryFailedMessageAndCountsThem(): void
    {
        $this->storage->save(OutboxMessageBuilder::create()->withId('f-1')->withStatus(OutboxStatus::Failed)->withAttempts(3)->build());
        $this->storage->save(OutboxMessageBuilder::create()->withId('f-2')->withStatus(OutboxStatus::Failed)->withAttempts(3)->build());
        $this->storage->save(OutboxMessageBuilder::create()->withId('p-1')->withStatus(OutboxStatus::Published)->build());

        Assert::same($this->outbox->requeueFailed(), 2);

        Assert::same($this->storage->getById('f-1')?->getStatus(), OutboxStatus::Pending);
        Assert::same($this->storage->getById('f-1')?->getAttempts(), 0);
        Assert::same($this->storage->getById('f-2')?->getStatus(), OutboxStatus::Pending);
        Assert::same($this->storage->getById('p-1')?->getStatus(), OutboxStatus::Published);
        Assert::same($this->outbox->requeueFailed(), 0);
    }

    public function requeueFailedPassesTypesAndLimitToTheStorage(): void
    {
        $storage = Understudy::delegate(RequeueableStorageInterface::class, $this->storage);
        $outbox = new Outbox(storage: $storage, clock: $this->clock);
        foreach (['a', 'b', 'c'] as $type) {
            $this->storage->save(OutboxMessageBuilder::create()->withId('f-' . $type)->withType($type)->withStatus(OutboxStatus::Failed)->build());
        }

        Assert::same($outbox->requeueFailed(types: ['a', 'b'], limit: 1), 1);

        verify(fn() => $storage->findFailed(['a', 'b'], 1), times: 1);
        Assert::same($this->storage->getById('f-a')?->getStatus(), OutboxStatus::Pending);
        Assert::same($this->storage->getById('f-b')?->getStatus(), OutboxStatus::Failed);
        Assert::same($this->storage->getById('f-c')?->getStatus(), OutboxStatus::Failed);
    }

    public function requeueFailedDoesNotCountAMessageTheStorageRefused(): void
    {
        $storage = Understudy::for(RequeueableStorageInterface::class);
        $gone = OutboxMessageBuilder::create()->withId('f-1')->withStatus(OutboxStatus::Failed)->build();
        $still = OutboxMessageBuilder::create()->withId('f-2')->withStatus(OutboxStatus::Failed)->build();
        when(fn() => $storage->findFailed(Arg::any(), Arg::any()))->returns([$gone, $still]);
        when(fn() => $storage->requeue($gone))->returns(false);
        when(fn() => $storage->requeue($still))->returns(true);
        $outbox = new Outbox(storage: $storage, clock: $this->clock);

        Assert::same($outbox->requeueFailed(), 1);
    }
}
