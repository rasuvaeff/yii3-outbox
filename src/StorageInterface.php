<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox;

/**
 * @api
 */
interface StorageInterface
{
    public function save(OutboxMessage $message): void;

    /**
     * @param list<string> $types restrict to these message types; empty = all types
     *
     * @return list<OutboxMessage>
     */
    public function findPending(array $types = [], int $limit = 1000): array;

    public function markPublished(OutboxMessage $message): void;

    public function markFailed(OutboxMessage $message): void;

    public function getById(string $id): ?OutboxMessage;
}
