<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox;

/**
 * Default generator: 32 hex characters over 128 random bits — the format
 * outbox messages have always used, so it stays the default and nothing
 * changes for existing installations.
 *
 * @api
 */
final readonly class RandomHexIdGenerator implements MessageIdGeneratorInterface
{
    #[\Override]
    public function generate(): string
    {
        /** @var non-empty-string */
        return bin2hex(random_bytes(16));
    }
}
