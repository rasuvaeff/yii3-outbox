<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox;

/**
 * A storage that can persist several messages in one write.
 *
 * A request that produces five events records five messages inside the same
 * OLTP transaction as its business write. Through {@see StorageInterface::save()}
 * that is five statements; a backend able to express it as one multi-row
 * insert implements this interface, and {@see Outbox::recordMany()} uses it
 * when present.
 *
 * @api
 */
interface BatchSavingStorageInterface extends StorageInterface
{
    /**
     * Persists every message, under the same transactional contract as
     * {@see StorageInterface::save()}: through the application's connection,
     * inside the caller's transaction.
     *
     * An empty list is a no-op.
     *
     * @param list<OutboxMessage> $messages
     */
    public function saveBatch(array $messages): void;
}
