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
        $message = OutboxMessage::create(type: 'test', payload: '{}');

        $this->fixture->save($message);

        $retrieved = $this->fixture->getById($message->getId());

        $this->assertNotNull($retrieved);
        $this->assertSame($message->getId(), $retrieved->getId());
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
}
