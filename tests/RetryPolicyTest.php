<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\Yii3Outbox\RetryPolicy;

#[CoversClass(RetryPolicy::class)]
final class RetryPolicyTest extends TestCase
{
    private RetryPolicy $fixture;

    #[\Override]
    protected function setUp(): void
    {
        $this->fixture = new RetryPolicy(maxAttempts: 3, delaySeconds: 60);
    }

    #[Test]
    public function returnsConfiguredValues(): void
    {
        $this->assertSame(3, $this->fixture->getMaxAttempts());
        $this->assertSame(60, $this->fixture->getDelaySeconds());
    }

    #[Test]
    public function shouldRetryWhenAttemptsNotExhausted(): void
    {
        $message = OutboxMessageBuilder::create()
            ->withStatus(\Rasuvaeff\Yii3Outbox\OutboxStatus::Pending)
            ->withAttempts(2)
            ->build();

        $this->assertTrue($this->fixture->shouldRetry($message));
    }

    #[Test]
    public function shouldNotRetryWhenAttemptsExhausted(): void
    {
        $message = OutboxMessageBuilder::create()
            ->withStatus(\Rasuvaeff\Yii3Outbox\OutboxStatus::Pending)
            ->withAttempts(3)
            ->build();

        $this->assertFalse($this->fixture->shouldRetry($message));
    }

    #[Test]
    public function shouldNotRetryWhenAlreadyPublished(): void
    {
        $message = OutboxMessageBuilder::create()
            ->withStatus(\Rasuvaeff\Yii3Outbox\OutboxStatus::Published)
            ->withAttempts(0)
            ->build();

        $this->assertFalse($this->fixture->shouldRetry($message));
    }

    #[Test]
    public function isReadyForRetryWithNoLastAttempt(): void
    {
        $message = OutboxMessageBuilder::create()
            ->withStatus(\Rasuvaeff\Yii3Outbox\OutboxStatus::Pending)
            ->withAttempts(0)
            ->withLastAttemptAt(null)
            ->build();

        $this->assertTrue($this->fixture->isReadyForRetry($message));
    }

    #[Test]
    public function isReadyForRetryAfterDelayPassed(): void
    {
        $message = OutboxMessageBuilder::create()
            ->withStatus(\Rasuvaeff\Yii3Outbox\OutboxStatus::Pending)
            ->withAttempts(1)
            ->withLastAttemptAt(new DateTimeImmutable('-120 seconds'))
            ->build();

        $this->assertTrue($this->fixture->isReadyForRetry($message));
    }

    #[Test]
    public function isNotReadyForRetryBeforeDelay(): void
    {
        $message = OutboxMessageBuilder::create()
            ->withStatus(\Rasuvaeff\Yii3Outbox\OutboxStatus::Pending)
            ->withAttempts(1)
            ->withLastAttemptAt(new DateTimeImmutable())
            ->build();

        $this->assertFalse($this->fixture->isReadyForRetry($message));
    }

    #[Test]
    public function throwsOnMaxAttemptsLessThanOne(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Max attempts must be at least 1');

        new RetryPolicy(maxAttempts: 0);
    }

    #[Test]
    public function throwsOnNegativeDelay(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Delay seconds must be non-negative');

        new RetryPolicy(delaySeconds: -1);
    }

    #[Test]
    public function defaultConstructorValues(): void
    {
        $policy = new RetryPolicy();

        $this->assertSame(3, $policy->getMaxAttempts());
        $this->assertSame(60, $policy->getDelaySeconds());
    }
}
