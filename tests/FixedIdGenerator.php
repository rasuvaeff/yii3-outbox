<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox\Tests;

use Rasuvaeff\Yii3Outbox\MessageIdGeneratorInterface;

final class FixedIdGenerator implements MessageIdGeneratorInterface
{
    public int $calls = 0;

    /**
     * @param non-empty-string $id
     */
    public function __construct(
        private readonly string $id,
    ) {}

    #[\Override]
    public function generate(): string
    {
        ++$this->calls;

        return $this->id;
    }
}
