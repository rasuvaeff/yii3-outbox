<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox;

use ArrayIterator;
use Countable;
use DateTimeImmutable;
use IteratorAggregate;
use Traversable;

/**
 * @api
 *
 * @implements IteratorAggregate<string, OutboxMessage>
 */
final class InMemoryStorage implements RetryAwareStorageInterface, BatchAcknowledgingStorageInterface, BatchSavingStorageInterface, RequeueableStorageInterface, StatsAwareStorageInterface, IteratorAggregate, Countable
{
    /** @var array<string, OutboxMessage> */
    private array $messages = [];

    #[\Override]
    public function save(OutboxMessage $message): void
    {
        $this->messages[$message->getId()] = $message;
    }

    #[\Override]
    public function saveBatch(array $messages): void
    {
        foreach ($messages as $message) {
            $this->save($message);
        }
    }

    #[\Override]
    public function findPending(array $types = [], int $limit = 1000): array
    {
        return $this->findByStatus(OutboxStatus::Pending, $types, $limit);
    }

    #[\Override]
    public function findFailed(array $types = [], int $limit = 1000): array
    {
        return $this->findByStatus(OutboxStatus::Failed, $types, $limit);
    }

    /**
     * @param list<string> $types
     *
     * @return list<OutboxMessage>
     */
    private function findByStatus(OutboxStatus $status, array $types, int $limit): array
    {
        $found = [];

        foreach ($this->inClaimOrder() as $message) {
            if ($message->getStatus() !== $status) {
                continue;
            }

            if ($types !== [] && !in_array($message->getType(), $types, strict: true)) {
                continue;
            }

            $found[] = $message;

            if (count($found) >= $limit) {
                break;
            }
        }

        return $found;
    }

    #[\Override]
    public function requeue(OutboxMessage $message): bool
    {
        $id = $message->getId();
        $stored = $this->messages[$id] ?? null;

        if ($stored === null || $stored->getStatus() !== OutboxStatus::Failed) {
            return false;
        }

        $this->messages[$id] = $stored->withAttemptsReset();

        return true;
    }

    #[\Override]
    public function stats(): OutboxStats
    {
        $counts = [
            OutboxStatus::Pending->value => 0,
            OutboxStatus::Processing->value => 0,
            OutboxStatus::Published->value => 0,
            OutboxStatus::Failed->value => 0,
        ];
        $oldestPending = null;

        foreach ($this->messages as $message) {
            $counts[$message->getStatus()->value]++;

            if ($message->getStatus() === OutboxStatus::Pending
                && ($oldestPending === null || $message->getCreatedAt() < $oldestPending)
            ) {
                $oldestPending = $message->getCreatedAt();
            }
        }

        return new OutboxStats(
            pending: $counts[OutboxStatus::Pending->value],
            processing: $counts[OutboxStatus::Processing->value],
            published: $counts[OutboxStatus::Published->value],
            failed: $counts[OutboxStatus::Failed->value],
            oldestPendingCreatedAt: $oldestPending,
        );
    }

    #[\Override]
    public function claim(array $types = [], int $limit = 1000): array
    {
        return $this->claimMatching($types, $limit, static fn(OutboxMessage $message): bool => true);
    }

    #[\Override]
    public function claimReady(
        DateTimeImmutable $readyThreshold,
        int $maxAttempts,
        array $types = [],
        int $limit = 1000,
    ): array {
        return $this->claimMatching(
            $types,
            $limit,
            static function (OutboxMessage $message) use ($readyThreshold, $maxAttempts): bool {
                if ($message->getAttempts() >= $maxAttempts) {
                    return true;
                }

                $lastAttemptAt = $message->getLastAttemptAt();

                return $lastAttemptAt === null || $lastAttemptAt <= $readyThreshold;
            },
        );
    }

    /**
     * @param list<string> $types
     * @param callable(OutboxMessage): bool $isEligible
     *
     * @return list<OutboxMessage>
     */
    private function claimMatching(array $types, int $limit, callable $isEligible): array
    {
        $claimed = [];

        foreach ($this->inClaimOrder() as $message) {
            $id = $message->getId();
            if ($message->getStatus() !== OutboxStatus::Pending) {
                continue;
            }

            if ($types !== [] && !in_array($message->getType(), $types, strict: true)) {
                continue;
            }

            if (!$isEligible($message)) {
                continue;
            }

            $processing = $message->withStatus(OutboxStatus::Processing);
            $this->messages[$id] = $processing;
            $claimed[] = $processing;

            if (count($claimed) >= $limit) {
                break;
            }
        }

        return $claimed;
    }

    /**
     * The order a database storage hands messages out in: higher priority
     * first, then oldest first. Insertion order breaks the remaining ties, so
     * a priority-less outbox behaves exactly as before.
     *
     * @return list<OutboxMessage>
     */
    private function inClaimOrder(): array
    {
        $ordered = $this->messages;
        usort(
            $ordered,
            static fn(OutboxMessage $a, OutboxMessage $b): int => [$b->getPriority(), $a->getCreatedAt()] <=> [$a->getPriority(), $b->getCreatedAt()],
        );

        return $ordered;
    }

    #[\Override]
    public function markPublished(OutboxMessage $message): void
    {
        $this->messages[$message->getId()] = $message->withStatus(OutboxStatus::Published);
    }

    #[\Override]
    public function markPublishedBatch(array $messages): void
    {
        foreach ($messages as $message) {
            $this->markPublished($message);
        }
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

    #[\Override]
    public function count(): int
    {
        return count($this->messages);
    }

    public function clear(): void
    {
        $this->messages = [];
    }
}
