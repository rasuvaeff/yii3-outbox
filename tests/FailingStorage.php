<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox\Tests;

use Rasuvaeff\Yii3Outbox\InMemoryStorage;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\StorageInterface;

/**
 * An {@see InMemoryStorage} that can be told to fail one transition. Seeding
 * happens through `save()` before the flags are set, so a test can arrange a
 * batch and only then break the write it is interested in.
 */
final class FailingStorage implements StorageInterface
{
    public ?\Throwable $failSave = null;

    public ?\Throwable $failMarkPublished = null;

    public ?\Throwable $failMarkFailed = null;

    public function __construct(private readonly InMemoryStorage $inner = new InMemoryStorage()) {}

    #[\Override]
    public function save(OutboxMessage $message): void
    {
        if ($this->failSave !== null) {
            throw $this->failSave;
        }

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
        return $this->inner->claim($types, $limit);
    }

    #[\Override]
    public function markPublished(OutboxMessage $message): void
    {
        if ($this->failMarkPublished !== null) {
            throw $this->failMarkPublished;
        }

        $this->inner->markPublished($message);
    }

    #[\Override]
    public function markFailed(OutboxMessage $message): void
    {
        if ($this->failMarkFailed !== null) {
            throw $this->failMarkFailed;
        }

        $this->inner->markFailed($message);
    }

    #[\Override]
    public function getById(string $id): ?OutboxMessage
    {
        return $this->inner->getById($id);
    }
}
