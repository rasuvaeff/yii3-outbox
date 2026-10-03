<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * @api
 */
final readonly class OutboxMessage
{
    public function __construct(
        private string $id,
        private string $type,
        private string $payload,
        private OutboxStatus $status,
        private DateTimeImmutable $createdAt,
        private int $attempts = 0,
        private ?DateTimeImmutable $lastAttemptAt = null,
        private ?string $aggregateId = null,
        private int $priority = 0,
    ) {
        if ($id === '') {
            throw new InvalidArgumentException('Message id must not be empty');
        }

        if ($type === '') {
            throw new InvalidArgumentException('Message type must not be empty');
        }

        if ($attempts < 0) {
            throw new InvalidArgumentException('Attempts must be non-negative');
        }
    }

    /**
     * @param ?string $id the domain event's identifier when the message mirrors
     *                    one; null generates the historical random-hex format
     */
    public static function create(
        string $type,
        string $payload,
        ?string $aggregateId = null,
        ?DateTimeImmutable $createdAt = null,
        ?string $id = null,
        int $priority = 0,
    ): self {
        return new self(
            id: $id ?? (new RandomHexIdGenerator())->generate(),
            type: $type,
            payload: $payload,
            status: OutboxStatus::Pending,
            createdAt: $createdAt ?? new DateTimeImmutable(),
            aggregateId: $aggregateId,
            priority: $priority,
        );
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getPayload(): string
    {
        return $this->payload;
    }

    public function getStatus(): OutboxStatus
    {
        return $this->status;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getLastAttemptAt(): ?DateTimeImmutable
    {
        return $this->lastAttemptAt;
    }

    public function getAggregateId(): ?string
    {
        return $this->aggregateId;
    }

    /**
     * Higher is claimed first; equal priorities keep `createdAt` order.
     */
    public function getPriority(): int
    {
        return $this->priority;
    }

    public function withStatus(OutboxStatus $status): self
    {
        return new self(
            id: $this->id,
            type: $this->type,
            payload: $this->payload,
            status: $status,
            createdAt: $this->createdAt,
            attempts: $this->attempts,
            lastAttemptAt: $this->lastAttemptAt,
            aggregateId: $this->aggregateId,
            priority: $this->priority,
        );
    }

    public function withAttempt(DateTimeImmutable $at): self
    {
        return new self(
            id: $this->id,
            type: $this->type,
            payload: $this->payload,
            status: $this->status,
            createdAt: $this->createdAt,
            attempts: $this->attempts + 1,
            lastAttemptAt: $at,
            aggregateId: $this->aggregateId,
            priority: $this->priority,
        );
    }

    /**
     * The message as it was before its first attempt: `Pending`, zero
     * attempts, no last attempt. What a requeue puts back into the storage.
     */
    public function withAttemptsReset(): self
    {
        return new self(
            id: $this->id,
            type: $this->type,
            payload: $this->payload,
            status: OutboxStatus::Pending,
            createdAt: $this->createdAt,
            aggregateId: $this->aggregateId,
            priority: $this->priority,
        );
    }
}
