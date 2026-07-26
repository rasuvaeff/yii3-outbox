<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox;

use Psr\Clock\ClockInterface;

/**
 * @api
 */
final readonly class Outbox
{
    private MessageIdGeneratorInterface $idGenerator;

    /**
     * @param ?MessageIdGeneratorInterface $idGenerator null keeps the historical
     *                                                  format ({@see RandomHexIdGenerator})
     */
    public function __construct(
        private StorageInterface $storage,
        private ClockInterface $clock,
        ?MessageIdGeneratorInterface $idGenerator = null,
    ) {
        $this->idGenerator = $idGenerator ?? new RandomHexIdGenerator();
    }

    /**
     * Records a message through the configured storage.
     *
     * Must be called inside the same database transaction as the business write
     * this message describes — otherwise the two can diverge and the outbox
     * guarantee is void. See {@see StorageInterface::save()} for the full
     * contract.
     *
     * @param ?string $id the domain event's identifier when this message mirrors
     *                    one — the generator is then not consulted, so republishing
     *                    the same event cannot produce two different message ids
     */
    public function record(
        string $type,
        string $payload,
        ?string $aggregateId = null,
        ?string $id = null,
    ): OutboxMessage {
        $message = OutboxMessage::create(
            type: $type,
            payload: $payload,
            aggregateId: $aggregateId,
            createdAt: $this->clock->now(),
            id: $id ?? $this->idGenerator->generate(),
        );

        $this->storage->save($message);

        return $message;
    }
}
