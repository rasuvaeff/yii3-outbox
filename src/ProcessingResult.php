<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox;

/**
 * @api
 */
final readonly class ProcessingResult
{
    public function __construct(
        public int $published,
        public int $failed,
        public int $skipped,
    ) {}

    public function total(): int
    {
        return $this->published + $this->failed + $this->skipped;
    }
}
