<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox;

use LogicException;
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
        $message = $this->draft(new OutboxMessageDraft(
            type: $type,
            payload: $payload,
            aggregateId: $aggregateId,
            id: $id,
        ));

        $this->storage->save($message);

        return $message;
    }

    /**
     * Records several messages, in one write when the storage is a
     * {@see BatchSavingStorageInterface} and one {@see StorageInterface::save()}
     * per message otherwise. Same transactional contract as {@see record()}.
     *
     * Every message carries the same `createdAt`: the clock is read once, so
     * the batch is one moment in the outbox's history, as it was one moment
     * in the application's. An empty list touches nothing and returns `[]`.
     *
     * @param list<OutboxMessageDraft> $drafts
     *
     * @return list<OutboxMessage> in the order given
     */
    public function recordMany(array $drafts): array
    {
        if ($drafts === []) {
            return [];
        }

        $now = $this->clock->now();
        $messages = [];

        foreach ($drafts as $draft) {
            $messages[] = $this->draft($draft, $now);
        }

        if ($this->storage instanceof BatchSavingStorageInterface) {
            $this->storage->saveBatch($messages);
        } else {
            foreach ($messages as $message) {
                $this->storage->save($message);
            }
        }

        return $messages;
    }

    /**
     * Puts `Failed` messages back in line — every one the storage reports for
     * the given types, up to $limit — and returns how many it moved.
     *
     * A message that stopped being `Failed` between the lookup and the
     * requeue is skipped and not counted; see
     * {@see RequeueableStorageInterface::requeue()}.
     *
     * @param list<string> $types restrict to these message types; empty = all types
     *
     * @return int<0, max>
     *
     * @throws LogicException when the storage is not a {@see RequeueableStorageInterface}
     */
    public function requeueFailed(array $types = [], int $limit = 1000): int
    {
        if (!$this->storage instanceof RequeueableStorageInterface) {
            throw new LogicException(sprintf(
                'Storage %s cannot requeue: it does not implement %s',
                $this->storage::class,
                RequeueableStorageInterface::class,
            ));
        }

        $requeued = 0;

        foreach ($this->storage->findFailed($types, $limit) as $message) {
            if ($this->storage->requeue($message)) {
                $requeued++;
            }
        }

        return $requeued;
    }

    private function draft(OutboxMessageDraft $draft, ?\DateTimeImmutable $createdAt = null): OutboxMessage
    {
        return OutboxMessage::create(
            type: $draft->type,
            payload: $draft->payload,
            aggregateId: $draft->aggregateId,
            createdAt: $createdAt ?? $this->clock->now(),
            id: $draft->id ?? $this->idGenerator->generate(),
        );
    }
}
