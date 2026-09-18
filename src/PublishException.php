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
        private readonly bool $terminal = false,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * A failure that no retry can fix: the receiver is gone, or rejected the
     * payload as malformed. {@see Processor} marks the message `Failed` at
     * once instead of spending the remaining attempts on it.
     */
    public static function terminal(
        string $message,
        OutboxMessage $outboxMessage,
        int $code = 0,
        ?\Throwable $previous = null,
    ): self {
        return new self($message, $outboxMessage, $code, $previous, terminal: true);
    }

    public function getOutboxMessage(): OutboxMessage
    {
        return $this->outboxMessage;
    }

    public function isTerminal(): bool
    {
        return $this->terminal;
    }
}
