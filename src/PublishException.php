<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox;

/**
 * @api
 */
final class PublishException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly OutboxMessage $outboxMessage,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getOutboxMessage(): OutboxMessage
    {
        return $this->outboxMessage;
    }
}
