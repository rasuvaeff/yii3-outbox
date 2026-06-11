<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox;

use ArrayIterator;
use IteratorAggregate;
use Traversable;

/**
 * @api
 *
 * @implements IteratorAggregate<string, OutboxMessage>
 */
final class InMemoryStorage implements StorageInterface, IteratorAggregate
{
    /** @var array<string, OutboxMessage> */
    private array $messages = [];

    #[\Override]
    public function save(OutboxMessage $message): void
    {
        $this->messages[$message->getId()] = $message;
    }

    #[\Override]
    public function findPending(array $types = [], int $limit = 1000): array
    {
        $pending = [];

        foreach ($this->messages as $message) {
            if ($message->getStatus() !== OutboxStatus::Pending) {
                continue;
            }

            if ($types !== [] && !in_array($message->getType(), $types, true)) {
                continue;
            }

            $pending[] = $message;

            if (count($pending) >= $limit) {
                break;
            }
        }

        return $pending;
    }

    #[\Override]
    public function markPublished(OutboxMessage $message): void
    {
        $this->messages[$message->getId()] = $message->withStatus(OutboxStatus::Published);
    }

    #[\Override]
    public function markFailed(OutboxMessage $message): void
    {
        $this->messages[$message->getId()] = $message->withStatus(OutboxStatus::Failed);
    }

    #[\Override]
    public function getById(string $id): ?OutboxMessage
    {
        return $this->messages[$id] ?? null;
    }

    #[\Override]
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->messages);
    }

    public function count(): int
    {
        return count($this->messages);
    }

    public function clear(): void
    {
        $this->messages = [];
    }
}
