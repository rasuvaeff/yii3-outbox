<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox\Tests;

use Rasuvaeff\Yii3Outbox\OutboxStatus;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(OutboxStatus::class)]
final class OutboxStatusTest
{
    public function hasExpectedCases(): void
    {
        $cases = OutboxStatus::cases();

        Assert::count($cases, 4);
        Assert::same(OutboxStatus::Pending->value, 'pending');
        Assert::same(OutboxStatus::Processing->value, 'processing');
        Assert::same(OutboxStatus::Published->value, 'published');
        Assert::same(OutboxStatus::Failed->value, 'failed');
    }

    public function createsFromValue(): void
    {
        Assert::same(OutboxStatus::from('pending'), OutboxStatus::Pending);
        Assert::same(OutboxStatus::from('processing'), OutboxStatus::Processing);
        Assert::same(OutboxStatus::from('published'), OutboxStatus::Published);
        Assert::same(OutboxStatus::from('failed'), OutboxStatus::Failed);
    }
}
