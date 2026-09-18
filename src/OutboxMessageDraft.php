<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox;

use InvalidArgumentException;

/**
 * What {@see Outbox::recordMany()} needs to know about one message before the
 * clock and the id generator have been consulted.
 *
 * @api
 */
final readonly class OutboxMessageDraft
{
    /**
     * @param ?string $id the domain event's identifier when the message mirrors
     *                    one; null lets the {@see Outbox} generate one
     */
    public function __construct(
        public string $type,
        public string $payload,
        public ?string $aggregateId = null,
        public ?string $id = null,
    ) {
        if ($type === '') {
            throw new InvalidArgumentException('Message type must not be empty');
        }

        if ($id === '') {
            throw new InvalidArgumentException('Message id must not be empty');
        }
    }
}
