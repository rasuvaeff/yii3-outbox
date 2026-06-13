<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\Yii3Outbox\OutboxStatus;

#[CoversClass(OutboxStatus::class)]
final class OutboxStatusTest extends TestCase
{
    #[Test]
    public function hasExpectedCases(): void
    {
        $cases = OutboxStatus::cases();

        $this->assertCount(4, $cases);
        $this->assertSame('pending', OutboxStatus::Pending->value);
        $this->assertSame('processing', OutboxStatus::Processing->value);
        $this->assertSame('published', OutboxStatus::Published->value);
        $this->assertSame('failed', OutboxStatus::Failed->value);
    }

    #[Test]
    public function createsFromValue(): void
    {
        $this->assertSame(OutboxStatus::Pending, OutboxStatus::from('pending'));
        $this->assertSame(OutboxStatus::Processing, OutboxStatus::from('processing'));
        $this->assertSame(OutboxStatus::Published, OutboxStatus::from('published'));
        $this->assertSame(OutboxStatus::Failed, OutboxStatus::from('failed'));
    }
}
