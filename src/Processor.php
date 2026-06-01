<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox;

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
        private int $batchSize = 100,
        private LoggerInterface $logger = new NullLogger(),
    ) {
        if ($batchSize < 1) {
            throw new \InvalidArgumentException('Batch size must be at least 1');
        }
    }

    public function process(): int
    {
        $messages = $this->storage->findPending(limit: $this->batchSize);
        $processed = 0;

        foreach ($messages as $message) {
            if (!$this->retryPolicy->isReadyForRetry($message)) {
                continue;
            }

            $message = $message->withAttempt();

            try {
                $this->publisher->publish($message);
                $this->storage->markPublished($message);

                $processed++;
            } catch (PublishException $e) {
                $this->logger->warning('Failed to publish outbox message', [
                    'messageId' => $message->getId(),
                    'attempts' => $message->getAttempts(),
                    'error' => $e->getMessage(),
                ]);

                if ($this->retryPolicy->shouldRetry($message)) {
                    $this->storage->markFailed($message);
                } else {
                    $this->storage->markFailed($message);
                }

                $processed++;
            }
        }

        return $processed;
    }
}
