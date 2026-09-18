<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox;

/**
 * A storage that can put `Failed` messages back in line.
 *
 * A message reaches `Failed` when its attempts run out or a publisher declares
 * the failure terminal. Once the cause is fixed — the receiver is back, the
 * payload bug is deployed — an operator wants those messages published after
 * all, and nothing in {@see StorageInterface} can move a `Failed` row.
 *
 * @api
 */
interface RequeueableStorageInterface extends StorageInterface
{
    /**
     * `Failed` messages, oldest first where the backend keeps an order.
     *
     * @param list<string> $types restrict to these message types; empty = all types
     *
     * @return list<OutboxMessage>
     */
    public function findFailed(array $types = [], int $limit = 1000): array;

    /**
     * Puts a `Failed` message back to `Pending` with its attempts reset, as
     * {@see OutboxMessage::withAttemptsReset()} describes, so that the next
     * claim treats it as never attempted.
     *
     * Only a message the storage currently holds as `Failed` is touched: one
     * that is `Pending`, `Processing` or `Published` by the time this runs —
     * a worker or another operator got there first — is left as it is, and
     * the method returns false.
     */
    public function requeue(OutboxMessage $message): bool;
}
