<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox;

use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @api
 */
final readonly class Processor
{
    /**
     * @param list<string> $types the message types this processor claims;
     *                            empty = every type in the storage. Several
     *                            consumers sharing one storage each name their
     *                            own types, or one of them acknowledges what
     *                            another was supposed to deliver
     */
    public function __construct(
        private StorageInterface $storage,
        private PublisherInterface $publisher,
        private RetryPolicy $retryPolicy,
        private ClockInterface $clock,
        private int $batchSize = 100,
        private LoggerInterface $logger = new NullLogger(),
        private array $types = [],
    ) {
        if ($batchSize < 1) {
            throw new InvalidArgumentException('Batch size must be at least 1');
        }

        foreach ($types as $type) {
            if ($type === '') {
                throw new InvalidArgumentException('Message type must not be empty');
            }
        }
    }

    /**
     * Publishes one claimed batch.
     *
     * Every message the batch claimed leaves `Processing`: published, saved
     * back as `Pending` for a later retry, or marked `Failed` once the
     * {@see RetryPolicy} has no attempts left. That holds for the exceptional
     * path too — whatever aborts the batch, the messages it claimed but never
     * attempted are released before the exception propagates.
     *
     * The release reaches for the same storage that may be the reason the batch
     * aborted, so it is best-effort by construction: a storage that is down
     * cannot be told anything, and those rows stay `Processing` until it comes
     * back and someone moves them. What the release does guarantee is that its
     * own failures are logged rather than thrown — the caller receives the
     * exception that aborted the batch, never one raised while reacting to it.
     *
     * @throws \Throwable whatever the publisher threw that was not a
     *                    {@see PublishException}, or whatever the storage threw
     *                    while recording the outcome of a message
     */
    public function process(): ProcessingResult
    {
        $now = $this->clock->now();
        $messages = $this->claimBatch($now);
        $published = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($messages as $index => $message) {
            try {
                // Attempts already spent: this message is not waiting for
                // anything, it is done. Saving it back as Pending — which a
                // plain isReadyForRetry() check does — makes it circle claim ->
                // skip -> save forever, invisible to an alert watching Failed.
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
                } catch (PublishException $e) {
                    $this->logger->warning('Failed to publish outbox message', [
                        'messageId' => $message->getId(),
                        'attempts' => $message->getAttempts(),
                        'terminal' => $e->isTerminal(),
                        'error' => $e->getMessage(),
                    ]);

                    if ($e->isTerminal()) {
                        // The publisher knows no retry can fix this; spending
                        // the remaining attempts would only delay the alert.
                        $this->storage->markFailed($message);
                    } else {
                        $this->persistFailure($message);
                    }

                    $failed++;

                    continue;
                } catch (\Throwable $e) {
                    // A publisher that lets something other than
                    // PublishException escape is a bug, and this method rethrows
                    // it rather than pretending the delivery merely failed.
                    $this->logger->error('Outbox publisher threw an unexpected exception', [
                        'messageId' => $message->getId(),
                        'attempts' => $message->getAttempts(),
                        'exception' => $e::class,
                        'error' => $e->getMessage(),
                    ]);

                    $this->persistDuringAbort($message);

                    throw $e;
                }

                try {
                    $this->storage->markPublished($message);
                } catch (\Throwable $e) {
                    // The message reached its consumer; only the record of that
                    // did not. Blaming the publisher here sends an operator to
                    // debug the wrong component during a storage incident.
                    //
                    // The message goes back to Pending and a later run will
                    // publish it again: this package delivers at least once, and
                    // the message id is the consumer's deduplication key. The
                    // alternative — leaving it Processing — is a row nothing in
                    // this API can move.
                    $this->logger->error('Outbox message was published but could not be marked published', [
                        'messageId' => $message->getId(),
                        'attempts' => $message->getAttempts(),
                        'exception' => $e::class,
                        'error' => $e->getMessage(),
                    ]);

                    $this->persistDuringAbort($message);

                    throw $e;
                }

                $published++;
            } catch (\Throwable $e) {
                // Whatever ended this message, the rest of the batch is still
                // claimed and nothing in this API would move it out of
                // Processing on its own.
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
     * Claims a batch, letting the storage apply the retry policy itself when it
     * can.
     *
     * The `isReadyForRetry()` check in the loop above stays either way. It is
     * not redundant with the pushdown: a plain {@see StorageInterface} has no
     * way to honour it, and one that does may still hand back more than asked —
     * time moves between the query and this loop. The pushdown removes work; it
     * is not what makes the result correct.
     *
     * @return list<OutboxMessage>
     */
    private function claimBatch(DateTimeImmutable $now): array
    {
        if ($this->storage instanceof RetryAwareStorageInterface) {
            return $this->storage->claimReady(
                readyThreshold: $this->retryPolicy->readyThreshold($now),
                maxAttempts: $this->retryPolicy->getMaxAttempts(),
                types: $this->types,
                limit: $this->batchSize,
            );
        }

        return $this->storage->claim(types: $this->types, limit: $this->batchSize);
    }

    /**
     * Applies the retry policy to a message whose delivery did not complete.
     */
    private function persistFailure(OutboxMessage $message): void
    {
        if ($this->retryPolicy->shouldRetry($message)) {
            $this->storage->save($message->withStatus(OutboxStatus::Pending));

            return;
        }

        $this->storage->markFailed($message);
    }

    /**
     * Same, on a batch that is already aborting. A storage failure here is
     * logged rather than thrown: it would otherwise replace the exception the
     * caller needs to see with a symptom of it.
     */
    private function persistDuringAbort(OutboxMessage $message): void
    {
        try {
            $this->persistFailure($message);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to persist an outbox message while aborting the batch', [
                'messageId' => $message->getId(),
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Puts back messages this batch claimed but never attempted. One that
     * arrived with no attempts left is terminated rather than saved as
     * `Pending` — exactly what the loop above would have done on reaching it,
     * and the only way the release does not recreate the state this class
     * exists to avoid.
     *
     * Each message is released independently and a failure to release one is
     * logged, not thrown: the storage may well be what aborted the batch, and
     * the caller must still receive that original exception.
     *
     * @param list<OutboxMessage> $messages
     */
    private function release(array $messages): void
    {
        foreach ($messages as $message) {
            try {
                if ($this->retryPolicy->shouldRetry($message)) {
                    $this->storage->save($message->withStatus(OutboxStatus::Pending));

                    continue;
                }

                $this->terminateExhausted($message);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to release a claimed outbox message', [
                    'messageId' => $message->getId(),
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                ]);
            }
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
