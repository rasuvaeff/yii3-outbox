<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox\Tests;

use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\PublisherInterface;
use Rasuvaeff\Yii3Outbox\PublishException;

final class StubPublisher implements PublisherInterface
{
    public bool $shouldFail = false;

    /**
     * Ids that fail with a {@see PublishException} while the rest succeed —
     * for batches where the loop must carry on past a failure.
     *
     * @var list<string>
     */
    public array $failIds = [];
    public ?OutboxMessage $lastPublished = null;

    /**
     * Anything the publisher throws that is not a {@see PublishException} —
     * a transport exception it forgot to wrap, a TypeError, an \Error.
     */
    public ?\Throwable $throwUnexpected = null;

    /**
     * @var list<string>
     */
    public array $publishedIds = [];

    #[\Override]
    public function publish(OutboxMessage $message): void
    {
        $this->lastPublished = $message;
        $this->publishedIds[] = $message->getId();

        if ($this->throwUnexpected !== null) {
            throw $this->throwUnexpected;
        }

        if ($this->shouldFail || in_array($message->getId(), $this->failIds, strict: true)) {
            throw new PublishException(
                message: 'Publish failed',
                outboxMessage: $message,
            );
        }
    }
}
