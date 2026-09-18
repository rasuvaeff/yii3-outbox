<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A snapshot of how many messages sit in each status, and how long the oldest
 * `Pending` one has been waiting.
 *
 * @api
 */
final readonly class OutboxStats
{
    /**
     * @param ?DateTimeImmutable $oldestPendingCreatedAt null when nothing is pending
     */
    public function __construct(
        public int $pending,
        public int $processing,
        public int $published,
        public int $failed,
        public ?DateTimeImmutable $oldestPendingCreatedAt = null,
    ) {
        foreach (['pending' => $pending, 'processing' => $processing, 'published' => $published, 'failed' => $failed] as $name => $count) {
            if ($count < 0) {
                throw new InvalidArgumentException(sprintf('Count "%s" must be non-negative, got %d', $name, $count));
            }
        }

        if ($pending === 0 && $oldestPendingCreatedAt !== null) {
            throw new InvalidArgumentException('Oldest pending timestamp requires at least one pending message');
        }
    }

    public function total(): int
    {
        return $this->pending + $this->processing + $this->published + $this->failed;
    }

    public function countOf(OutboxStatus $status): int
    {
        return match ($status) {
            OutboxStatus::Pending => $this->pending,
            OutboxStatus::Processing => $this->processing,
            OutboxStatus::Published => $this->published,
            OutboxStatus::Failed => $this->failed,
        };
    }

    /**
     * Age of the oldest pending message at $now; null when nothing is pending.
     *
     * @return ?int seconds
     */
    public function oldestPendingAgeSeconds(DateTimeImmutable $now): ?int
    {
        if ($this->oldestPendingCreatedAt === null) {
            return null;
        }

        return max(0, $now->getTimestamp() - $this->oldestPendingCreatedAt->getTimestamp());
    }
}
