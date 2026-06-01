<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\Yii3Outbox\ProcessingResult;

#[CoversClass(ProcessingResult::class)]
final class ProcessingResultTest extends TestCase
{
    #[Test]
    public function holdsCounters(): void
    {
        $result = new ProcessingResult(published: 3, failed: 1, skipped: 2);

        $this->assertSame(3, $result->published);
        $this->assertSame(1, $result->failed);
        $this->assertSame(2, $result->skipped);
    }

    #[Test]
    public function totalSumsAllCounters(): void
    {
        $result = new ProcessingResult(published: 3, failed: 1, skipped: 2);

        $this->assertSame(6, $result->total());
    }

    #[Test]
    public function totalIsZeroWhenEmpty(): void
    {
        $result = new ProcessingResult(published: 0, failed: 0, skipped: 0);

        $this->assertSame(0, $result->total());
    }
}
