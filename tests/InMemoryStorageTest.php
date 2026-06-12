<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\Yii3Outbox\InMemoryStorage;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\OutboxStatus;

#[CoversClass(InMemoryStorage::class)]
final class InMemoryStorageTest extends TestCase
{
    private InMemoryStorage $fixture;

    #[\Override]
    protected function setUp(): void
    {
        $this->fixture = new InMemoryStorage();
    }

    #[Test]
    public function savesAndRetrievesMessage(): void
    {
        $message = OutboxMessageBuilder::create()
            ->withId('msg-1')
            ->withAggregateId('order-42')
            ->build();

        $this->fixture->save($message);

        $retrieved = $this->fixture->getById('msg-1');

        $this->assertNotNull($retrieved);
        $this->assertSame('msg-1', $retrieved->getId());
        $this->assertSame('order-42', $retrieved->getAggregateId());
    }

    #[Test]
    public function returnsNullForUnknownId(): void
    {
        $this->assertNull($this->fixture->getById('nonexistent'));
    }

    #[Test]
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

        $this->assertCount(1, $result);
        $this->assertSame('pending-1', $result[0]->getId());
    }

    #[Test]
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

        $this->assertCount(3, $result);
    }

    #[Test]
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

        $this->assertSame(['exp-1', 'conv-1'], array_map(
            static fn(OutboxMessage $message): string => $message->getId(),
            $result,
        ));
    }

    #[Test]
    public function findPendingWithEmptyTypesReturnsAllTypes(): void
    {
        $this->fixture->save(
            OutboxMessageBuilder::create()->withId('exp-1')->withType('ab.exposure')->build(),
        );
        $this->fixture->save(
            OutboxMessageBuilder::create()->withId('other-1')->withType('order.created')->build(),
        );

        $this->assertCount(2, $this->fixture->findPending());
    }

    #[Test]
    public function markPublishedUpdatesStatus(): void
    {
        $message = OutboxMessageBuilder::create()
            ->withId('msg-1')
            ->withStatus(OutboxStatus::Pending)
            ->build();

        $this->fixture->save($message);
        $this->fixture->markPublished($message);

        $retrieved = $this->fixture->getById('msg-1');

        $this->assertNotNull($retrieved);
        $this->assertSame(OutboxStatus::Published, $retrieved->getStatus());
    }

    #[Test]
    public function markFailedUpdatesStatus(): void
    {
        $message = OutboxMessageBuilder::create()
            ->withId('msg-1')
            ->withStatus(OutboxStatus::Pending)
            ->build();

        $this->fixture->save($message);
        $this->fixture->markFailed($message);

        $retrieved = $this->fixture->getById('msg-1');

        $this->assertNotNull($retrieved);
        $this->assertSame(OutboxStatus::Failed, $retrieved->getStatus());
    }

    #[Test]
    public function clearRemovesAllMessages(): void
    {
        $this->fixture->save(
            OutboxMessageBuilder::create()->withId('msg-1')->build(),
        );

        $this->fixture->clear();

        $this->assertSame(0, $this->fixture->count());
    }

    #[Test]
    public function countReturnsNumberOfMessages(): void
    {
        $this->assertSame(0, $this->fixture->count());

        $this->fixture->save(OutboxMessageBuilder::create()->withId('a')->build());
        $this->fixture->save(OutboxMessageBuilder::create()->withId('b')->build());

        $this->assertSame(2, $this->fixture->count());
    }

    #[Test]
    public function iteratesOverMessages(): void
    {
        $this->fixture->save(OutboxMessageBuilder::create()->withId('a')->build());
        $this->fixture->save(OutboxMessageBuilder::create()->withId('b')->build());

        $ids = [];

        foreach ($this->fixture as $message) {
            $ids[] = $message->getId();
        }

        $this->assertSame(['a', 'b'], $ids);
    }

    #[Test]
    public function findPendingSkipsNonPendingThatPrecedesAPendingMessage(): void
    {
        // Non-pending first: the status filter must `continue`, not `break`, or the
        // following pending message would be missed.
        $this->fixture->save(OutboxMessageBuilder::create()->withId('published-first')->withStatus(OutboxStatus::Published)->build());
        $this->fixture->save(OutboxMessageBuilder::create()->withId('pending-after')->withStatus(OutboxStatus::Pending)->build());

        $this->assertSame(['pending-after'], array_map(
            static fn(OutboxMessage $message): string => $message->getId(),
            $this->fixture->findPending(),
        ));
    }

    #[Test]
    public function findPendingSkipsNonMatchingTypeThatPrecedesAMatch(): void
    {
        // Non-matching type first: the type filter must `continue`, not `break`.
        $this->fixture->save(OutboxMessageBuilder::create()->withId('other-first')->withType('order.created')->build());
        $this->fixture->save(OutboxMessageBuilder::create()->withId('exp-after')->withType('ab.exposure')->build());

        $this->assertSame(['exp-after'], array_map(
            static fn(OutboxMessage $message): string => $message->getId(),
            $this->fixture->findPending(types: ['ab.exposure']),
        ));
    }
}
