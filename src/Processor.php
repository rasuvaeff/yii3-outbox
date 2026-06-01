<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox;

use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @api
 */
final readonly class Processor
{
    public function __construct(
        private StorageInterface $storage,
        private PublisherInterface $publisher,
        private RetryPolicy $retryPolicy,
        private ClockInterface $clock,
        private int $batchSize = 100,
        private LoggerInterface $logger = new NullLogger(),
    ) {
        if ($batchSize < 1) {
            throw new InvalidArgumentException('Batch size must be at least 1');
        }
    }

    public function process(): ProcessingResult
    {
        $messages = $this->storage->findPending(limit: $this->batchSize);
        $published = 0;
        $failed = 0;
        $skipped = 0;
        $now = $this->clock->now();

        foreach ($messages as $message) {
            if (!$this->retryPolicy->isReadyForRetry($message, $now)) {
                $skipped++;

                continue;
            }

            $message = $message->withAttempt($now);

            try {
                $this->publisher->publish($message);
                $this->storage->markPublished($message);

                $published++;
            } catch (PublishException $e) {
                $this->logger->warning('Failed to publish outbox message', [
                    'messageId' => $message->getId(),
                    'attempts' => $message->getAttempts(),
                    'error' => $e->getMessage(),
                ]);

                if ($this->retryPolicy->shouldRetry($message)) {
                    $this->storage->save($message);
                } else {
                    $this->storage->markFailed($message);
                }

                $failed++;
            }
        }

        return new ProcessingResult(
            published: $published,
            failed: $failed,
            skipped: $skipped,
        );
    }
}
