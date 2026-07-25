<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox;

/**
 * Produces the identifier of an outbox message — the primary key of the
 * outbox table and, when messages are exported to ClickHouse, the
 * deduplication key of the ReplacingMergeTree.
 *
 * The shipped {@see RandomHexIdGenerator} keeps the historical format;
 * a monotonic generator (UUIDv7, ULID) makes inserts append instead of
 * scattering across pages and lets batches be fetched in a stable order.
 *
 * A message that mirrors a domain event should carry that event's id
 * instead — pass it to {@see Outbox::record()} and the generator is not
 * consulted at all.
 *
 * @api
 */
interface MessageIdGeneratorInterface
{
    /**
     * @return non-empty-string
     */
    public function generate(): string;
}
