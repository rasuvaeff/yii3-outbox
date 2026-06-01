<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox;

use Psr\Clock\ClockInterface;

/**
 * @api
 */
final readonly class Outbox
{
    public function __construct(
        private StorageInterface $storage,
        private ClockInterface $clock,
    ) {}

    public function record(
        string $type,
        string $payload,
        ?string $aggregateId = null,
    ): OutboxMessage {
        $message = OutboxMessage::create(
            type: $type,
            payload: $payload,
            aggregateId: $aggregateId,
            createdAt: $this->clock->now(),
        );

        $this->storage->save($message);

        return $message;
    }
}
