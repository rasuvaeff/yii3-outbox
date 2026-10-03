<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox\Tests;

use DateTimeImmutable;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\PropertyTesting\StateMachine\CommandSequence;
use Rasuvaeff\PropertyTesting\StateMachine\StateMachine;
use Rasuvaeff\Yii3Outbox\InMemoryStorage;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\OutboxStatus;
use Rasuvaeff\Yii3Outbox\Tests\Support\ClaimCommand;
use Rasuvaeff\Yii3Outbox\Tests\Support\FailCommand;
use Rasuvaeff\Yii3Outbox\Tests\Support\OutboxHarness;
use Rasuvaeff\Yii3Outbox\Tests\Support\PublishCommand;
use Rasuvaeff\Yii3Outbox\Tests\Support\RequeueCommand;
use Rasuvaeff\Yii3Outbox\Tests\Support\SaveCommand;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(InMemoryStorage::class)]
final class InMemoryStorageTest
{
    private InMemoryStorage $fixture;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->fixture = new InMemoryStorage();
    }

    public function savesAndRetrievesMessage(): void
    {
        $message = OutboxMessageBuilder::create()
            ->withId('msg-1')
            ->withAggregateId('order-42')
            ->build();

        $this->fixture->save($message);

        $retrieved = $this->fixture->getById('msg-1');

        Assert::notNull($retrieved);
        Assert::same($retrieved->getId(), 'msg-1');
        Assert::same($retrieved->getAggregateId(), 'order-42');
    }

    public function returnsNullForUnknownId(): void
    {
        Assert::null($this->fixture->getById('nonexistent'));
    }

    public function findPendingReturnsOnlyPendingMessages(): void
    {
        $pending = OutboxMessageBuilder::create()
            ->withId('pending-1')
            ->withStatus(OutboxStatus::Pending)
            ->build();
        $published = OutboxMessageBuilder::create()
            ->withId('published-1')
            ->withStatus(OutboxStatus::Published)
            ->build();

        $this->fixture->save($pending);
        $this->fixture->save($published);

        $result = $this->fixture->findPending();

        Assert::count($result, 1);
        Assert::same($result[0]->getId(), 'pending-1');
    }

    public function findPendingRespectsLimit(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->fixture->save(
                OutboxMessageBuilder::create()
                    ->withId('msg-' . $i)
                    ->withStatus(OutboxStatus::Pending)
                    ->build(),
            );
        }

        $result = $this->fixture->findPending(limit: 3);

        Assert::count($result, 3);
    }

    public function findPendingFiltersByType(): void
    {
        $this->fixture->save(
            OutboxMessageBuilder::create()->withId('exp-1')->withType('ab.exposure')->build(),
        );
        $this->fixture->save(
            OutboxMessageBuilder::create()->withId('conv-1')->withType('ab.conversion')->build(),
        );
        $this->fixture->save(
            OutboxMessageBuilder::create()->withId('other-1')->withType('order.created')->build(),
        );

        $result = $this->fixture->findPending(types: ['ab.exposure', 'ab.conversion']);

        Assert::same(
            array_map(
                static fn(OutboxMessage $message): string => $message->getId(),
                $result,
            ),
            ['exp-1', 'conv-1'],
        );
    }

    public function findPendingWithEmptyTypesReturnsAllTypes(): void
    {
        $this->fixture->save(
            OutboxMessageBuilder::create()->withId('exp-1')->withType('ab.exposure')->build(),
        );
        $this->fixture->save(
            OutboxMessageBuilder::create()->withId('other-1')->withType('order.created')->build(),
        );

        Assert::count($this->fixture->findPending(), 2);
    }

    public function markPublishedUpdatesStatus(): void
    {
        $message = OutboxMessageBuilder::create()
            ->withId('msg-1')
            ->withStatus(OutboxStatus::Pending)
            ->build();

        $this->fixture->save($message);
        $this->fixture->markPublished($message);

        $retrieved = $this->fixture->getById('msg-1');

        Assert::notNull($retrieved);
        Assert::same($retrieved->getStatus(), OutboxStatus::Published);
    }

    public function markPublishedBatchUpdatesEveryStatus(): void
    {
        $first = OutboxMessageBuilder::create()->withId('msg-1')->withStatus(OutboxStatus::Processing)->build();
        $second = OutboxMessageBuilder::create()->withId('msg-2')->withStatus(OutboxStatus::Processing)->build();
        $untouched = OutboxMessageBuilder::create()->withId('msg-3')->withStatus(OutboxStatus::Processing)->build();

        $this->fixture->save($first);
        $this->fixture->save($second);
        $this->fixture->save($untouched);

        $this->fixture->markPublishedBatch([$first, $second]);

        Assert::same($this->fixture->getById('msg-1')?->getStatus(), OutboxStatus::Published);
        Assert::same($this->fixture->getById('msg-2')?->getStatus(), OutboxStatus::Published);
        Assert::same($this->fixture->getById('msg-3')?->getStatus(), OutboxStatus::Processing);
    }

    public function markPublishedBatchWithEmptyListChangesNothing(): void
    {
        $message = OutboxMessageBuilder::create()->withId('msg-1')->withStatus(OutboxStatus::Processing)->build();
        $this->fixture->save($message);

        $this->fixture->markPublishedBatch([]);

        Assert::same($this->fixture->getById('msg-1')?->getStatus(), OutboxStatus::Processing);
        Assert::count($this->fixture, 1);
    }

    public function markFailedUpdatesStatus(): void
    {
        $message = OutboxMessageBuilder::create()
            ->withId('msg-1')
            ->withStatus(OutboxStatus::Pending)
            ->build();

        $this->fixture->save($message);
        $this->fixture->markFailed($message);

        $retrieved = $this->fixture->getById('msg-1');

        Assert::notNull($retrieved);
        Assert::same($retrieved->getStatus(), OutboxStatus::Failed);
    }

    public function clearRemovesAllMessages(): void
    {
        $this->fixture->save(
            OutboxMessageBuilder::create()->withId('msg-1')->build(),
        );

        $this->fixture->clear();

        Assert::same($this->fixture->count(), 0);
    }

    public function countReturnsNumberOfMessages(): void
    {
        Assert::same($this->fixture->count(), 0);

        $this->fixture->save(OutboxMessageBuilder::create()->withId('a')->build());
        $this->fixture->save(OutboxMessageBuilder::create()->withId('b')->build());

        Assert::same($this->fixture->count(), 2);
    }

    public function isCountable(): void
    {
        Assert::instanceOf($this->fixture, \Countable::class);
        Assert::same(count($this->fixture), 0);

        $this->fixture->save(OutboxMessageBuilder::create()->withId('a')->build());

        Assert::same(count($this->fixture), 1);
    }

    public function iteratesOverMessages(): void
    {
        $this->fixture->save(OutboxMessageBuilder::create()->withId('a')->build());
        $this->fixture->save(OutboxMessageBuilder::create()->withId('b')->build());

        $ids = [];

        foreach ($this->fixture as $message) {
            $ids[] = $message->getId();
        }

        Assert::same($ids, ['a', 'b']);
    }

    public function findPendingSkipsNonPendingThatPrecedesAPendingMessage(): void
    {
        $this->fixture->save(OutboxMessageBuilder::create()->withId('published-first')->withStatus(OutboxStatus::Published)->build());
        $this->fixture->save(OutboxMessageBuilder::create()->withId('pending-after')->withStatus(OutboxStatus::Pending)->build());

        Assert::same(
            array_map(
                static fn(OutboxMessage $message): string => $message->getId(),
                $this->fixture->findPending(),
            ),
            ['pending-after'],
        );
    }

    public function findPendingSkipsNonMatchingTypeThatPrecedesAMatch(): void
    {
        $this->fixture->save(OutboxMessageBuilder::create()->withId('other-first')->withType('order.created')->build());
        $this->fixture->save(OutboxMessageBuilder::create()->withId('exp-after')->withType('ab.exposure')->build());

        Assert::same(
            array_map(
                static fn(OutboxMessage $message): string => $message->getId(),
                $this->fixture->findPending(types: ['ab.exposure']),
            ),
            ['exp-after'],
        );
    }

    public function claimTransitionsPendingToProcessing(): void
    {
        $this->fixture->save(OutboxMessageBuilder::create()->withId('a')->withStatus(OutboxStatus::Pending)->build());

        $claimed = $this->fixture->claim();

        Assert::count($claimed, 1);
        Assert::same($claimed[0]->getId(), 'a');
        Assert::same($claimed[0]->getStatus(), OutboxStatus::Processing);
        Assert::same($this->fixture->getById('a')?->getStatus(), OutboxStatus::Processing);
    }

    public function claimDoesNotReturnNonPendingMessages(): void
    {
        $this->fixture->save(OutboxMessageBuilder::create()->withId('pub')->withStatus(OutboxStatus::Published)->build());
        $this->fixture->save(OutboxMessageBuilder::create()->withId('proc')->withStatus(OutboxStatus::Processing)->build());
        $this->fixture->save(OutboxMessageBuilder::create()->withId('fail')->withStatus(OutboxStatus::Failed)->build());

        Assert::same($this->fixture->claim(), []);
    }

    public function claimRespectsLimit(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->fixture->save(OutboxMessageBuilder::create()->withId('m' . $i)->withStatus(OutboxStatus::Pending)->build());
        }

        $claimed = $this->fixture->claim(limit: 2);

        Assert::count($claimed, 2);
        Assert::count($this->fixture->findPending(), 3);
    }

    public function claimFiltersByType(): void
    {
        $this->fixture->save(OutboxMessageBuilder::create()->withId('exp')->withType('ab.exposure')->build());
        $this->fixture->save(OutboxMessageBuilder::create()->withId('conv')->withType('ab.conversion')->build());
        $this->fixture->save(OutboxMessageBuilder::create()->withId('order')->withType('order.created')->build());

        $claimed = $this->fixture->claim(types: ['ab.exposure', 'ab.conversion']);

        Assert::same(
            array_map(
                static fn(OutboxMessage $message): string => $message->getId(),
                $claimed,
            ),
            ['exp', 'conv'],
        );
        Assert::count($this->fixture->findPending(), 1);
        Assert::same($this->fixture->findPending()[0]->getId(), 'order');
    }

    public function claimHandsOutHigherPriorityFirstThenOldest(): void
    {
        $t0 = new DateTimeImmutable('2026-10-03 10:00:00');
        $this->fixture->save(OutboxMessageBuilder::create()->withId('bulk-old')->withCreatedAt($t0)->build());
        $this->fixture->save(OutboxMessageBuilder::create()->withId('urgent-new')->withPriority(10)->withCreatedAt($t0->modify('+2 seconds'))->build());
        $this->fixture->save(OutboxMessageBuilder::create()->withId('urgent-old')->withPriority(10)->withCreatedAt($t0->modify('+1 second'))->build());
        $this->fixture->save(OutboxMessageBuilder::create()->withId('bulk-new')->withCreatedAt($t0->modify('+3 seconds'))->build());

        $ids = array_map(static fn(OutboxMessage $message): string => $message->getId(), $this->fixture->claim(limit: 3));

        Assert::same($ids, ['urgent-old', 'urgent-new', 'bulk-old']);
        Assert::same($this->fixture->findPending()[0]->getId(), 'bulk-new');
    }

    public function claimReadyKeepsPriorityOrderAmongEligibleMessages(): void
    {
        $t0 = new DateTimeImmutable('2026-10-03 10:00:00');
        $this->fixture->save(OutboxMessageBuilder::create()->withId('bulk')->withCreatedAt($t0)->build());
        $this->fixture->save(OutboxMessageBuilder::create()->withId('urgent-in-backoff')->withPriority(10)->withCreatedAt($t0)->withAttempts(1)->withLastAttemptAt($t0->modify('+5 minutes'))->build());
        $this->fixture->save(OutboxMessageBuilder::create()->withId('urgent')->withPriority(5)->withCreatedAt($t0->modify('+1 second'))->build());

        $ids = array_map(
            static fn(OutboxMessage $message): string => $message->getId(),
            $this->fixture->claimReady(readyThreshold: $t0->modify('+1 minute'), maxAttempts: 3),
        );

        Assert::same($ids, ['urgent', 'bulk'], 'backoff still filters; the rest keeps priority order');
    }

    /**
     * @param list<array{int, int}> $specs priority, createdAt offset in seconds
     */
    #[Property(runs: 200)]
    public function claimOrderIsPriorityDescThenCreatedAtAsc(array $specs, int $limit): void
    {
        $t0 = new DateTimeImmutable('2026-10-03 10:00:00');
        foreach ($specs as $index => [$priority, $offset]) {
            $this->fixture->save(OutboxMessageBuilder::create()->withId('m' . $index)->withPriority($priority)->withCreatedAt($t0->modify(sprintf('+%d seconds', $offset)))->build());
        }

        $claimed = $this->fixture->claim(limit: $limit);
        $keys = array_map(static fn(OutboxMessage $message): array => [-$message->getPriority(), $message->getCreatedAt()->getTimestamp()], $claimed);
        $sorted = $keys;
        sort($sorted);

        Assert::same($keys, $sorted, 'claimed batch is sorted by (priority desc, createdAt asc)');
        Assert::count($claimed, min($limit, count($specs)));
        $lowestClaimed = $keys === [] ? null : end($keys);
        foreach ($this->fixture->findPending() as $left) {
            $leftKey = [-$left->getPriority(), $left->getCreatedAt()->getTimestamp()];
            Assert::true($lowestClaimed === null || $leftKey >= $lowestClaimed, 'nothing left behind outranks what was claimed');
        }
    }

    /** @return array<string, ArbitraryInterface> */
    public static function claimOrderIsPriorityDescThenCreatedAtAscGenerators(): array
    {
        return [
            'specs' => Gen::arrayOf(Gen::tuple(Gen::intBetween(-2, 12), Gen::intBetween(0, 30)), maxSize: 12),
            'limit' => Gen::intBetween(1, 8),
        ];
    }

    public function claimSecondCallDoesNotReturnAlreadyClaimedMessages(): void
    {
        $this->fixture->save(OutboxMessageBuilder::create()->withId('a')->withStatus(OutboxStatus::Pending)->build());

        $this->fixture->claim();
        $second = $this->fixture->claim();

        Assert::same($second, []);
    }

    public function claimSkipsNonPendingThatPrecedesAPendingMessage(): void
    {
        $this->fixture->save(OutboxMessageBuilder::create()->withId('pub')->withStatus(OutboxStatus::Published)->build());
        $this->fixture->save(OutboxMessageBuilder::create()->withId('pending')->withStatus(OutboxStatus::Pending)->build());

        $claimed = $this->fixture->claim();

        Assert::count($claimed, 1);
        Assert::same($claimed[0]->getId(), 'pending');
    }

    public function claimSkipsNonMatchingTypeThatPrecedesAMatch(): void
    {
        $this->fixture->save(OutboxMessageBuilder::create()->withId('other')->withType('order.created')->build());
        $this->fixture->save(OutboxMessageBuilder::create()->withId('exp')->withType('ab.exposure')->build());

        $claimed = $this->fixture->claim(types: ['ab.exposure']);

        Assert::count($claimed, 1);
        Assert::same($claimed[0]->getId(), 'exp');
    }

    public function claimReadyTakesMessagesNeverAttempted(): void
    {
        $this->fixture->save(OutboxMessageBuilder::create()->withId('fresh')->withStatus(OutboxStatus::Pending)->build());

        $claimed = $this->fixture->claimReady(new DateTimeImmutable('2026-06-01 11:59:00'), 3);

        Assert::count($claimed, 1);
        Assert::same($claimed[0]->getId(), 'fresh');
        Assert::same($claimed[0]->getStatus(), OutboxStatus::Processing);
    }

    public function claimReadySkipsMessagesAttemptedAfterTheThreshold(): void
    {
        $this->fixture->save(
            OutboxMessageBuilder::create()
                ->withId('recent')
                ->withStatus(OutboxStatus::Pending)
                ->withAttempts(1)
                ->withLastAttemptAt(new DateTimeImmutable('2026-06-01 11:59:30'))
                ->build(),
        );

        Assert::same($this->fixture->claimReady(new DateTimeImmutable('2026-06-01 11:59:00'), 3), []);
        Assert::same($this->fixture->getById('recent')?->getStatus(), OutboxStatus::Pending);
    }

    public function claimReadyTakesAMessageAttemptedExactlyAtTheThreshold(): void
    {
        $this->fixture->save(
            OutboxMessageBuilder::create()
                ->withId('boundary')
                ->withStatus(OutboxStatus::Pending)
                ->withAttempts(1)
                ->withLastAttemptAt(new DateTimeImmutable('2026-06-01 11:59:00'))
                ->build(),
        );

        $claimed = $this->fixture->claimReady(new DateTimeImmutable('2026-06-01 11:59:00'), 3);

        Assert::count($claimed, 1);
        Assert::same($claimed[0]->getId(), 'boundary');
    }

    /**
     * An exhausted message has nowhere to go but `markFailed()`, and the caller
     * can only fail what it was handed. Filtering it out on its backoff would
     * strand it as `Pending` forever.
     */
    public function claimReadyTakesExhaustedMessagesRegardlessOfTheThreshold(): void
    {
        $this->fixture->save(
            OutboxMessageBuilder::create()
                ->withId('exhausted')
                ->withStatus(OutboxStatus::Pending)
                ->withAttempts(3)
                ->withLastAttemptAt(new DateTimeImmutable('2026-06-01 11:59:59'))
                ->build(),
        );

        $claimed = $this->fixture->claimReady(new DateTimeImmutable('2026-06-01 11:59:00'), 3);

        Assert::count($claimed, 1);
        Assert::same($claimed[0]->getId(), 'exhausted');
    }

    public function claimReadySkipsANotReadyMessageThatPrecedesAReadyOne(): void
    {
        $this->fixture->save(
            OutboxMessageBuilder::create()
                ->withId('not-ready')
                ->withStatus(OutboxStatus::Pending)
                ->withAttempts(1)
                ->withLastAttemptAt(new DateTimeImmutable('2026-06-01 11:59:30'))
                ->build(),
        );
        $this->fixture->save(OutboxMessageBuilder::create()->withId('ready')->withStatus(OutboxStatus::Pending)->build());

        $claimed = $this->fixture->claimReady(new DateTimeImmutable('2026-06-01 11:59:00'), 3);

        Assert::count($claimed, 1);
        Assert::same($claimed[0]->getId(), 'ready');
    }

    public function claimReadyRespectsLimitAndTypes(): void
    {
        $this->fixture->save(OutboxMessageBuilder::create()->withId('exp1')->withType('ab.exposure')->build());
        $this->fixture->save(OutboxMessageBuilder::create()->withId('order')->withType('order.created')->build());
        $this->fixture->save(OutboxMessageBuilder::create()->withId('exp2')->withType('ab.exposure')->build());

        $claimed = $this->fixture->claimReady(
            new DateTimeImmutable('2026-06-01 11:59:00'),
            3,
            ['ab.exposure'],
            1,
        );

        Assert::count($claimed, 1);
        Assert::same($claimed[0]->getId(), 'exp1');
        Assert::count($this->fixture->findPending(), 2);
    }

    public function claimReadyDoesNotReturnNonPendingMessages(): void
    {
        $this->fixture->save(OutboxMessageBuilder::create()->withId('proc')->withStatus(OutboxStatus::Processing)->build());
        $this->fixture->save(OutboxMessageBuilder::create()->withId('pub')->withStatus(OutboxStatus::Published)->build());

        Assert::same($this->fixture->claimReady(new DateTimeImmutable('2026-06-01 11:59:00'), 3), []);
    }

    public function saveBatchPersistsEveryMessage(): void
    {
        $first = OutboxMessageBuilder::create()->withId('msg-1')->build();
        $second = OutboxMessageBuilder::create()->withId('msg-2')->withAggregateId('order-42')->build();

        $this->fixture->saveBatch([$first, $second]);

        Assert::count($this->fixture, 2);
        Assert::same($this->fixture->getById('msg-1'), $first);
        Assert::same($this->fixture->getById('msg-2'), $second);
    }

    public function saveBatchWithEmptyListChangesNothing(): void
    {
        $this->fixture->saveBatch([]);

        Assert::count($this->fixture, 0);
    }

    public function findFailedReturnsOnlyFailedMessages(): void
    {
        foreach (OutboxStatus::cases() as $status) {
            $this->fixture->save(
                OutboxMessageBuilder::create()->withId('msg-' . $status->value)->withStatus($status)->build(),
            );
        }

        $failed = $this->fixture->findFailed();

        Assert::count($failed, 1);
        Assert::same($failed[0]->getId(), 'msg-failed');
    }

    public function findFailedFiltersByTypeAndRespectsLimit(): void
    {
        foreach (['a', 'b', 'c'] as $index => $type) {
            $this->fixture->save(
                OutboxMessageBuilder::create()->withId('msg-' . $index)->withType($type)->withStatus(OutboxStatus::Failed)->build(),
            );
            $this->fixture->save(
                OutboxMessageBuilder::create()->withId('msg-' . $index . '-second')->withType($type)->withStatus(OutboxStatus::Failed)->build(),
            );
        }

        $ids = static fn(array $messages): array => array_map(static fn(OutboxMessage $m): string => $m->getId(), $messages);

        Assert::same($ids($this->fixture->findFailed(types: ['b', 'c'])), ['msg-1', 'msg-1-second', 'msg-2', 'msg-2-second']);
        Assert::same($ids($this->fixture->findFailed(types: ['b', 'c'], limit: 3)), ['msg-1', 'msg-1-second', 'msg-2']);
        Assert::same($ids($this->fixture->findFailed(limit: 1)), ['msg-0']);
        Assert::count($this->fixture->findFailed(types: ['zzz']), 0);
    }

    public function findFailedSkipsANonFailedMessageThatPrecedesAFailedOne(): void
    {
        $this->fixture->save(OutboxMessageBuilder::create()->withId('msg-1')->withStatus(OutboxStatus::Pending)->build());
        $this->fixture->save(OutboxMessageBuilder::create()->withId('msg-2')->withStatus(OutboxStatus::Failed)->build());

        Assert::same($this->fixture->findFailed(limit: 1)[0]->getId(), 'msg-2');
    }

    public function requeueMovesAFailedMessageBackToPendingWithAttemptsReset(): void
    {
        $failed = OutboxMessageBuilder::create()
            ->withId('msg-1')
            ->withStatus(OutboxStatus::Failed)
            ->withAttempts(3)
            ->withLastAttemptAt(new DateTimeImmutable('2026-06-01 11:00:00'))
            ->withAggregateId('order-42')
            ->build();
        $this->fixture->save($failed);

        Assert::true($this->fixture->requeue($failed));

        $stored = $this->fixture->getById('msg-1');
        Assert::notNull($stored);
        Assert::same($stored->getStatus(), OutboxStatus::Pending);
        Assert::same($stored->getAttempts(), 0);
        Assert::null($stored->getLastAttemptAt());
        Assert::same($stored->getAggregateId(), 'order-42');
        Assert::same($stored->getCreatedAt(), $failed->getCreatedAt());
    }

    public function requeueDecidesOnTheStoredStatusNotTheArgument(): void
    {
        // The argument may be a stale snapshot: what matters is what the
        // storage holds now.
        $stale = OutboxMessageBuilder::create()->withId('msg-1')->withStatus(OutboxStatus::Failed)->withAttempts(3)->build();
        $this->fixture->save($stale->withStatus(OutboxStatus::Processing));

        Assert::false($this->fixture->requeue($stale));
        Assert::same($this->fixture->getById('msg-1')?->getStatus(), OutboxStatus::Processing);
        Assert::same($this->fixture->getById('msg-1')?->getAttempts(), 3);

        $this->fixture->save($stale->withStatus(OutboxStatus::Pending));
        $this->fixture->markFailed($stale->withStatus(OutboxStatus::Pending));

        Assert::true($this->fixture->requeue($stale->withStatus(OutboxStatus::Published)));
        Assert::same($this->fixture->getById('msg-1')?->getStatus(), OutboxStatus::Pending);
    }

    #[DataProvider('nonFailedStatusProvider')]
    public function requeueLeavesANonFailedMessageUntouched(OutboxStatus $status): void
    {
        $message = OutboxMessageBuilder::create()->withId('msg-1')->withStatus($status)->withAttempts(2)->build();
        $this->fixture->save($message);

        Assert::false($this->fixture->requeue($message));
        Assert::same($this->fixture->getById('msg-1'), $message);
    }

    public static function nonFailedStatusProvider(): iterable
    {
        yield 'pending' => [OutboxStatus::Pending];
        yield 'processing' => [OutboxStatus::Processing];
        yield 'published' => [OutboxStatus::Published];
    }

    public function requeueOfAnUnknownMessageIsFalse(): void
    {
        $message = OutboxMessageBuilder::create()->withId('ghost')->withStatus(OutboxStatus::Failed)->build();

        Assert::false($this->fixture->requeue($message));
        Assert::count($this->fixture, 0);
    }

    public function statsOfAnEmptyStorageAreAllZero(): void
    {
        $stats = $this->fixture->stats();

        Assert::same([$stats->pending, $stats->processing, $stats->published, $stats->failed], [0, 0, 0, 0]);
        Assert::null($stats->oldestPendingCreatedAt);
    }

    public function statsCountEveryStatusAndFindTheOldestPending(): void
    {
        $older = new DateTimeImmutable('2026-06-01 09:00:00');
        $newer = new DateTimeImmutable('2026-06-01 10:00:00');
        $oldestButNotPending = new DateTimeImmutable('2026-06-01 08:00:00');

        // Saved newest first, so "oldest" is not "first saved".
        $this->fixture->save(OutboxMessageBuilder::create()->withId('p-new')->withCreatedAt($newer)->build());
        $this->fixture->save(OutboxMessageBuilder::create()->withId('p-old')->withCreatedAt($older)->build());
        $this->fixture->save(OutboxMessageBuilder::create()->withId('f')->withCreatedAt($oldestButNotPending)->withStatus(OutboxStatus::Failed)->build());
        $this->fixture->save(OutboxMessageBuilder::create()->withId('x1')->withStatus(OutboxStatus::Processing)->build());
        $this->fixture->save(OutboxMessageBuilder::create()->withId('x2')->withStatus(OutboxStatus::Processing)->build());
        $this->fixture->save(OutboxMessageBuilder::create()->withId('pub')->withStatus(OutboxStatus::Published)->build());

        $stats = $this->fixture->stats();

        Assert::same($stats->pending, 2);
        Assert::same($stats->processing, 2);
        Assert::same($stats->published, 1);
        Assert::same($stats->failed, 1);
        Assert::same($stats->oldestPendingCreatedAt, $older);
    }

    public function statsKeepTheFirstOfTwoPendingMessagesCreatedAtTheSameInstant(): void
    {
        // Equal timestamps as two objects: a strict "earlier than" keeps the
        // one already found, so the answer is stable under save order.
        $first = new DateTimeImmutable('2026-06-01 09:00:00');
        $second = new DateTimeImmutable('2026-06-01 09:00:00');

        $this->fixture->save(OutboxMessageBuilder::create()->withId('p-1')->withCreatedAt($first)->build());
        $this->fixture->save(OutboxMessageBuilder::create()->withId('p-2')->withCreatedAt($second)->build());

        Assert::same($this->fixture->stats()->oldestPendingCreatedAt, $first);
    }

    public function statsReportTheEarlierOfTwoPendingTimestampsWhateverTheSaveOrder(): void
    {
        $older = new DateTimeImmutable('2026-06-01 09:00:00');
        $newer = new DateTimeImmutable('2026-06-01 10:00:00');

        $this->fixture->save(OutboxMessageBuilder::create()->withId('p-old')->withCreatedAt($older)->build());
        $this->fixture->save(OutboxMessageBuilder::create()->withId('p-new')->withCreatedAt($newer)->build());

        Assert::same($this->fixture->stats()->oldestPendingCreatedAt, $older);
    }

    /**
     * Model-based test: under any interleaving of save, claim, markPublished and
     * markFailed, every message's stored status tracks a simple model (the list
     * of statuses in save order), and `findPending()` stays consistent with the
     * per-message status — coverage the isolated single-operation tests above do
     * not reach.
     */
    #[Property(runs: 300, timeoutMs: 2000)]
    public function interleavedLifecycleOperationsTrackTheModel(CommandSequence $sequence): void
    {
        $harness = new OutboxHarness();

        $kinds = [];

        foreach ($sequence->commands as $command) {
            $kinds[$command::class] = true;
        }

        // The outcomes worth naming are the ones a uniform draw over four
        // commands practically never produces: a run that never claims
        // (everything stays pending) and one that never publishes (a queue
        // that only accumulates). Measured over 400 swarmed sequences: 39.5%
        // never claim, 38.5% never publish, 24.2% use all four. Each floor is
        // under half its share, so a seed cannot trip it.
        Classify::cover($kinds !== [] && !isset($kinds[ClaimCommand::class]), 'never claimed', 15.0);
        Classify::cover($kinds !== [] && !isset($kinds[PublishCommand::class]), 'never published', 15.0);
        Classify::cover(\count($kinds) === 5, 'all five commands present', 5.0);
        Classify::cover(isset($kinds[RequeueCommand::class], $kinds[FailCommand::class]), 'requeue after fail possible', 10.0);

        StateMachine::check($sequence, static fn(): OutboxHarness => $harness);

        // findPending() must agree with the per-message statuses read via getById().
        $statuses = $harness->statuses();
        $pending = count(array_filter($statuses, static fn(string $status): bool => $status === 'pending'));
        Assert::same($harness->pendingCount(), $pending);

        // stats() is the same information aggregated: one count per status,
        // and the oldest Pending message is the first one in save order that
        // is still Pending, since createdAt increases with every save.
        $stats = $harness->stats();
        $counted = array_count_values($statuses);
        Assert::same($stats->pending, $counted['pending'] ?? 0);
        Assert::same($stats->processing, $counted['processing'] ?? 0);
        Assert::same($stats->published, $counted['published'] ?? 0);
        Assert::same($stats->failed, $counted['failed'] ?? 0);
        Assert::same($stats->total(), count($statuses));
        Assert::same($stats->oldestPendingCreatedAt?->getTimestamp(), $harness->oldestPendingCreatedAt()?->getTimestamp());
    }

    /** @return array<string, ArbitraryInterface> */
    public static function interleavedLifecycleOperationsTrackTheModelGenerators(): array
    {
        // Swarmed: each sequence may use only a subset of the four commands.
        // Drawing uniformly from all four, a sequence that never publishes —
        // an outbox that only accumulates — needs every one of up to a hundred
        // picks to miss the same command, which effectively never happens.
        // minLength stays at the default 0, so a subset from which nothing
        // applies yields an empty sequence rather than GenerationExhausted.
        return ['sequence' => Gen::swarm(Gen::commands([], [
            Gen::constant(new SaveCommand()),
            Gen::constant(new ClaimCommand()),
            Gen::map(Gen::intBetween(0, 4), static fn(int $index): PublishCommand => new PublishCommand($index)),
            Gen::map(Gen::intBetween(0, 4), static fn(int $index): FailCommand => new FailCommand($index)),
            Gen::map(Gen::intBetween(0, 4), static fn(int $index): RequeueCommand => new RequeueCommand($index)),
        ]))];
    }
}
