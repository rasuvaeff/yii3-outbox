<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox\Tests;

use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\PublisherInterface;
use Rasuvaeff\Yii3Outbox\PublishException;

final class StubPublisher implements PublisherInterface
{
    public bool $shouldFail = false;
    public ?OutboxMessage $lastPublished = null;

    #[\Override]
    public function publish(OutboxMessage $message): void
    {
        $this->lastPublished = $message;

        if ($this->shouldFail) {
            throw new PublishException(
                message: 'Publish failed',
                outboxMessage: $message,
            );
        }
    }
}
