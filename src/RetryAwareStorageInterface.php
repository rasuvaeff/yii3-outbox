<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox;

use DateTimeImmutable;

/**
 * A storage that can apply the retry policy inside the claim itself.
 *
 * {@see StorageInterface::claim()} takes every `Pending` message and leaves the
 * caller to discard the ones still waiting out their backoff — each of which is
 * then written back as `Pending`. That is two writes per backing-off message per
 * worker iteration, and every one of them occupies a slot in the batch that a
 * message ready to go could have used.
 *
 * A backend able to express the same predicate in its own query language
 * implements this interface, and {@see Processor} claims through it instead.
 * The interface is separate rather than a parameter on `claim()` because adding
 * one there would break every third-party implementation.
 *
 * @api
 */
interface RetryAwareStorageInterface extends StorageInterface
{
    /**
     * Atomically transitions up to $limit `Pending` messages to `Processing`,
     * skipping those still waiting for their next attempt.
     *
     * A message qualifies when any of the following holds:
     *
     * - it has never been attempted (`getLastAttemptAt() === null`);
     * - it was last attempted at or before $readyThreshold;
     * - it has already spent $maxAttempts attempts.
     *
     * The last clause is not an optimisation and must not be dropped. A message
     * out of attempts cannot be retried, and the only thing left to do with it
     * is mark it `Failed` — which the caller can only do to a message it was
     * given. Filter it out and nothing ever terminates it: it stays `Pending`,
     * invisible to an alert watching `Failed`, forever.
     *
     * $readyThreshold comes from {@see RetryPolicy::readyThreshold()}. An
     * implementation must not derive it from the policy itself; the delay is
     * the core's business, not the backend's.
     *
     * The contract of {@see StorageInterface::claim()} otherwise carries over
     * in full: the caller must move every returned message out of `Processing`.
     *
     * @param list<string> $types restrict to these message types; empty = all types
     *
     * @return list<OutboxMessage>
     */
    public function claimReady(
        DateTimeImmutable $readyThreshold,
        int $maxAttempts,
        array $types = [],
        int $limit = 1000,
    ): array;
}
