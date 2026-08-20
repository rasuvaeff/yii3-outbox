<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox\Tests;

use DateTimeImmutable;
use Rasuvaeff\Yii3Outbox\InMemoryStorage;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\RetryAwareStorageInterface;

/**
 * An {@see InMemoryStorage} that counts the calls it receives.
 *
 * A write-back of a backing-off message is `save($message->withStatus(Pending))`
 * on a message that is already `Pending` — it changes nothing observable in the
 * store, so only a counter can tell it happened.
 */
final class RecordingStorage implements RetryAwareStorageInterface
{
    public int $saves = 0;

    public int $claimCalls = 0;

    public int $claimReadyCalls = 0;

    public function __construct(private readonly InMemoryStorage $inner = new InMemoryStorage()) {}

    #[\Override]
    public function save(OutboxMessage $message): void
    {
        $this->saves++;
        $this->inner->save($message);
    }

    /**
     * Seeds a message without counting it as a write.
     */
    public function seed(OutboxMessage $message): void
    {
        $this->inner->save($message);
    }

    #[\Override]
    public function findPending(array $types = [], int $limit = 1000): array
    {
        return $this->inner->findPending($types, $limit);
    }

    #[\Override]
    public function claim(array $types = [], int $limit = 1000): array
    {
        $this->claimCalls++;

        return $this->inner->claim($types, $limit);
    }

    #[\Override]
    public function claimReady(
        DateTimeImmutable $readyThreshold,
        int $maxAttempts,
        array $types = [],
        int $limit = 1000,
    ): array {
        $this->claimReadyCalls++;

        return $this->inner->claimReady($readyThreshold, $maxAttempts, $types, $limit);
    }

    #[\Override]
    public function markPublished(OutboxMessage $message): void
    {
        $this->inner->markPublished($message);
    }

    #[\Override]
    public function markFailed(OutboxMessage $message): void
    {
        $this->inner->markFailed($message);
    }

    #[\Override]
    public function getById(string $id): ?OutboxMessage
    {
        return $this->inner->getById($id);
    }
}
