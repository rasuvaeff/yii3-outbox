<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox;

use DateTimeImmutable;

/**
 * @api
 */
final readonly class RetryPolicy
{
    public function __construct(
        private int $maxAttempts = 3,
        private int $delaySeconds = 60,
    ) {
        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException('Max attempts must be at least 1');
        }

        if ($delaySeconds < 0) {
            throw new \InvalidArgumentException('Delay seconds must be non-negative');
        }
    }

    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function getDelaySeconds(): int
    {
        return $this->delaySeconds;
    }

    public function shouldRetry(OutboxMessage $message): bool
    {
        if ($message->getStatus() === OutboxStatus::Published) {
            return false;
        }

        return $message->getAttempts() < $this->maxAttempts;
    }

    public function isReadyForRetry(OutboxMessage $message): bool
    {
        if (!$this->shouldRetry($message)) {
            return false;
        }

        $lastAttempt = $message->getLastAttemptAt();

        if ($lastAttempt === null) {
            return true;
        }

        $nextAttemptAt = $lastAttempt->modify('+' . $this->delaySeconds . ' seconds');

        return new DateTimeImmutable() >= $nextAttemptAt;
    }
}
