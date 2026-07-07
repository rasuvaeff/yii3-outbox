<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox\Tests\Support;

use DateTimeImmutable;
use Rasuvaeff\Yii3Outbox\InMemoryStorage;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\OutboxStatus;

/**
 * Stateful-test harness around an {@see InMemoryStorage}: it remembers the ids of
 * saved messages in order so a publish/fail command can target one by index.
 *
 * The system under test for the model-based property in
 * {@see \Rasuvaeff\Yii3Outbox\Tests\InMemoryStorageTest}.
 */
final class OutboxHarness
{
    private readonly InMemoryStorage $storage;

    /** @var list<string> */
    private array $ids = [];

    private int $seq = 0;

    public function __construct()
    {
        $this->storage = new InMemoryStorage();
    }

    public function save(): void
    {
        $message = OutboxMessage::create(
            type: 'evt',
            payload: 'payload-' . $this->seq,
            createdAt: new DateTimeImmutable('@' . $this->seq),
        );
        ++$this->seq;

        $this->storage->save($message);
        $this->ids[] = $message->getId();
    }

    public function claim(): void
    {
        $this->storage->claim();
    }

    public function publish(int $index): void
    {
        $message = $this->messageAt($index);

        if ($message !== null) {
            $this->storage->markPublished($message);
        }
    }

    public function fail(int $index): void
    {
        $message = $this->messageAt($index);

        if ($message !== null) {
            $this->storage->markFailed($message);
        }
    }

    /**
     * The status of every saved message, in save order — mirrors the model.
     *
     * @return list<string>
     */
    public function statuses(): array
    {
        return array_map(
            fn(string $id): string => ($this->storage->getById($id)?->getStatus() ?? OutboxStatus::Pending)->value,
            $this->ids,
        );
    }

    public function pendingCount(): int
    {
        return count($this->storage->findPending());
    }

    private function messageAt(int $index): ?OutboxMessage
    {
        if ($index < 0 || $index >= count($this->ids)) {
            return null;
        }

        return $this->storage->getById($this->ids[$index]);
    }
}
