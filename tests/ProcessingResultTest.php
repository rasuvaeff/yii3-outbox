<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox\Tests;

use Rasuvaeff\Yii3Outbox\ProcessingResult;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(ProcessingResult::class)]
final class ProcessingResultTest
{
    public function holdsCounters(): void
    {
        $result = new ProcessingResult(published: 3, failed: 1, skipped: 2);

        Assert::same($result->published, 3);
        Assert::same($result->failed, 1);
        Assert::same($result->skipped, 2);
    }

    public function totalSumsAllCounters(): void
    {
        $result = new ProcessingResult(published: 3, failed: 1, skipped: 2);

        Assert::same($result->total(), 6);
    }

    public function totalIsZeroWhenEmpty(): void
    {
        $result = new ProcessingResult(published: 0, failed: 0, skipped: 0);

        Assert::same($result->total(), 0);
    }
}
