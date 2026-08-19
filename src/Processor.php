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

    /**
     * Publishes one claimed batch.
     *
     * Every message the batch claimed leaves `Processing`: published, saved
     * back as `Pending` for a later retry, or marked `Failed` once the
     * {@see RetryPolicy} has no attempts left. That holds for the exceptional
     * path too — a publisher throwing something other than
     * {@see PublishException} releases the rest of the batch before the
     * exception propagates.
     *
     * @throws \Throwable whatever the publisher threw that was not a {@see PublishException}
     */
    public function process(): ProcessingResult
    {
        $messages = $this->storage->claim(limit: $this->batchSize);
        $published = 0;
        $failed = 0;
        $skipped = 0;
        $now = $this->clock->now();

        foreach ($messages as $index => $message) {
            // Attempts already spent: this message is not waiting for anything,
            // it is done. Saving it back as Pending — which a plain
            // isReadyForRetry() check does — makes it circle claim -> skip ->
            // save forever, invisible to an alert watching Failed.
            if (!$this->retryPolicy->shouldRetry($message)) {
                $this->terminateExhausted($message);
                $failed++;

                continue;
            }

            if (!$this->retryPolicy->isReadyForRetry($message, $now)) {
                $this->storage->save($message->withStatus(OutboxStatus::Pending));
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
                    $this->storage->save($message->withStatus(OutboxStatus::Pending));
                } else {
                    $this->storage->markFailed($message);
                }

                $failed++;
            } catch (\Throwable $e) {
                // A publisher that lets something other than PublishException
                // escape is a bug, and this method rethrows it rather than
                // pretending the delivery merely failed. What it must not do is
                // leave rows behind in Processing: nothing in the API can move
                // them back, so every message this batch claimed is released
                // before the exception continues on its way.
                $this->logger->error('Outbox publisher threw an unexpected exception', [
                    'messageId' => $message->getId(),
                    'attempts' => $message->getAttempts(),
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                ]);

                if ($this->retryPolicy->shouldRetry($message)) {
                    $this->storage->save($message->withStatus(OutboxStatus::Pending));
                } else {
                    $this->storage->markFailed($message);
                }

                $this->release(\array_slice($messages, $index + 1));

                throw $e;
            }
        }

        return new ProcessingResult(
            published: $published,
            failed: $failed,
            skipped: $skipped,
        );
    }

    /**
     * Puts back messages this batch claimed but never attempted. One that
     * arrived with no attempts left is terminated rather than saved as
     * `Pending` — exactly what the loop above would have done on reaching it,
     * and the only way the release does not recreate the state this class
     * exists to avoid.
     *
     * @param list<OutboxMessage> $messages
     */
    private function release(array $messages): void
    {
        foreach ($messages as $message) {
            if ($this->retryPolicy->shouldRetry($message)) {
                $this->storage->save($message->withStatus(OutboxStatus::Pending));

                continue;
            }

            $this->terminateExhausted($message);
        }
    }

    private function terminateExhausted(OutboxMessage $message): void
    {
        $this->logger->warning('Outbox message exhausted its retries', [
            'messageId' => $message->getId(),
            'attempts' => $message->getAttempts(),
        ]);

        $this->storage->markFailed($message);
    }
}
