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
     * @return list<OutboxMessage>
     */
    public function findPending(int $limit = 100): array;

    public function markPublished(OutboxMessage $message): void;

    public function markFailed(OutboxMessage $message): void;

    public function getById(string $id): ?OutboxMessage;
}
