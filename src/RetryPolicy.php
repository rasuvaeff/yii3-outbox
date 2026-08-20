<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox;

use DateTimeImmutable;
use InvalidArgumentException;

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
            throw new InvalidArgumentException('Max attempts must be at least 1');
        }

        if ($delaySeconds < 0) {
            throw new InvalidArgumentException('Delay seconds must be non-negative');
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

    /**
     * The instant a message must have been last attempted at or before to be
     * ready for another attempt now.
     *
     * `isReadyForRetry()` asks the question one message at a time; this asks it
     * once, as a boundary a storage can push into its own query. The two are
     * the same inequality — `lastAttemptAt + delay <= now` rearranged — so a
     * backend filtering on this threshold selects exactly the messages the
     * per-message check would have accepted.
     *
     * @see RetryAwareStorageInterface::claimReady()
     */
    public function readyThreshold(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->modify('-' . $this->delaySeconds . ' seconds');
    }

    public function isReadyForRetry(OutboxMessage $message, DateTimeImmutable $now): bool
    {
        if (!$this->shouldRetry($message)) {
            return false;
        }

        $lastAttempt = $message->getLastAttemptAt();

        if ($lastAttempt === null) {
            return true;
        }

        $nextAttemptAt = $lastAttempt->modify('+' . $this->delaySeconds . ' seconds');

        return $now >= $nextAttemptAt;
    }
}
