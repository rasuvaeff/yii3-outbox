<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox;

/**
 * @api
 */
interface SerializerInterface
{
    public function serialize(OutboxMessage $message): string;

    public function deserialize(string $data): OutboxMessage;
}
