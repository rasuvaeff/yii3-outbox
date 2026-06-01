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

    public static function create(
        string $type,
        string $payload,
        ?string $aggregateId = null,
        ?DateTimeImmutable $createdAt = null,
    ): self {
        return new self(
            id: bin2hex(random_bytes(16)),
            type: $type,
            payload: $payload,
            status: OutboxStatus::Pending,
            createdAt: $createdAt ?? new DateTimeImmutable(),
            aggregateId: $aggregateId,
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
        );
    }
}
