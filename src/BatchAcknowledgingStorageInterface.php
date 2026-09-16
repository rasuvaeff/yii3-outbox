<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox;

/**
 * A storage that can acknowledge a whole batch of published messages at once.
 *
 * {@see StorageInterface::markPublished()} takes one message, so a caller that
 * delivers a batch as a unit — one bulk insert into an analytical store, one
 * multi-message broker call — acknowledges it with one write per message. Over
 * an SQL backend that is a thousand statements for a thousand-message batch,
 * every one of them landing in the same OLTP database the application writes
 * its business rows to.
 *
 * A backend able to express the acknowledgement as one statement implements
 * this interface, and a batching caller acknowledges through it instead. The
 * interface is separate rather than a parameter on `markPublished()` because
 * adding one there would break every third-party implementation.
 *
 * @api
 */
interface BatchAcknowledgingStorageInterface extends StorageInterface
{
    /**
     * Marks every given message `Published`, as {@see StorageInterface::markPublished()}
     * would do for each of them, but in as few writes as the backend allows.
     *
     * Whether an acknowledged message is kept as `Published` or removed
     * altogether is the storage's decision, not the caller's: the same policy
     * must apply to `markPublished()`, so that every producer of
     * acknowledgements agrees on what the storage holds afterwards.
     *
     * An empty list is a no-op.
     *
     * @param list<OutboxMessage> $messages
     */
    public function markPublishedBatch(array $messages): void;
}
