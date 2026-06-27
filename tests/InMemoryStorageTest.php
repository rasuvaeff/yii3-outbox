<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox\Tests;

use Rasuvaeff\Yii3Outbox\InMemoryStorage;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\OutboxStatus;
use Testo\Assert;
use Testo\Codecov\Covers;
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
}
