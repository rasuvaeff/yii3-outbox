<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox;

/**
 * @api
 */
interface PublisherInterface
{
    /**
     * @throws PublishException when publishing fails
     */
    public function publish(OutboxMessage $message): void;
}
