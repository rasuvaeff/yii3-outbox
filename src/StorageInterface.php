<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox;

/**
 * @api
 */
interface StorageInterface
{
    /**
     * Persists the message.
     *
     * The outbox pattern only holds if this write commits atomically with the
     * business write it describes. The core cannot enforce that: it opens no
     * transaction and knows nothing about the caller's connection. An
     * implementation is therefore required to write through the very connection
     * the application uses for its business tables, and the application is
     * required to call {@see Outbox::record()} inside the same transaction as
     * the business write:
     *
     * ```php
     * $db->transaction(static function () use ($orders, $outbox, $order): void {
     *     $orders->insert($order);
     *     $outbox->record(type: 'order.created', payload: $json);
     * });
     * ```
     *
     * Commit the business row without the message and the event is lost;
     * commit the message without the business row and consumers observe an
     * event that never happened. A storage backed by a different database
     * (or by a message broker) cannot provide this guarantee at all.
     */
    public function save(OutboxMessage $message): void;

    /**
     * @param list<string> $types restrict to these message types; empty = all types
     *
     * @return list<OutboxMessage>
     */
    public function findPending(array $types = [], int $limit = 1000): array;

    /**
     * Atomically transitions up to $limit Pending messages to Processing and returns them.
     * The caller must call markPublished(), markFailed(), or save($msg->withStatus(Pending))
     * for every claimed message — never leave a message in Processing indefinitely.
     *
     * Order: higher {@see OutboxMessage::getPriority()} first, then oldest
     * `createdAt` first — a backend must hand out every eligible message of a
     * higher priority before any of a lower one.
     *
     * @param list<string> $types restrict to these message types; empty = all types
     *
     * @return list<OutboxMessage>
     */
    public function claim(array $types = [], int $limit = 1000): array;

    public function markPublished(OutboxMessage $message): void;

    public function markFailed(OutboxMessage $message): void;

    public function getById(string $id): ?OutboxMessage;
}
