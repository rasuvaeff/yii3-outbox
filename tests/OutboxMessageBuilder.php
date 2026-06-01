<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox\Tests;

use DateTimeImmutable;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\OutboxStatus;

final class OutboxMessageBuilder
{
    private string $id = 'test-id';
    private string $type = 'test.event';
    private string $payload = '{}';
    private OutboxStatus $status = OutboxStatus::Pending;
    private DateTimeImmutable $createdAt;
    private int $attempts = 0;
    private ?DateTimeImmutable $lastAttemptAt = null;

    private function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    public static function create(): self
    {
        return new self();
    }

    public function withId(string $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function withStatus(OutboxStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function withAttempts(int $attempts): self
    {
        $this->attempts = $attempts;

        return $this;
    }

    public function withLastAttemptAt(?DateTimeImmutable $lastAttemptAt): self
    {
        $this->lastAttemptAt = $lastAttemptAt;

        return $this;
    }

    public function build(): OutboxMessage
    {
        return new OutboxMessage(
            id: $this->id,
            type: $this->type,
            payload: $this->payload,
            status: $this->status,
            createdAt: $this->createdAt,
            attempts: $this->attempts,
            lastAttemptAt: $this->lastAttemptAt,
        );
    }
}
