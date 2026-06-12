<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\Yii3Outbox\OutboxStatus;
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
            ->withStatus(OutboxStatus::Pending)
            ->withAttempts(2)
            ->build();

        $this->assertTrue($this->fixture->shouldRetry($message));
    }

    #[Test]
    public function shouldNotRetryWhenAttemptsExhausted(): void
    {
        $message = OutboxMessageBuilder::create()
            ->withStatus(OutboxStatus::Pending)
            ->withAttempts(3)
            ->build();

        $this->assertFalse($this->fixture->shouldRetry($message));
    }

    #[Test]
    public function shouldNotRetryWhenAlreadyPublished(): void
    {
        $message = OutboxMessageBuilder::create()
            ->withStatus(OutboxStatus::Published)
            ->withAttempts(0)
            ->build();

        $this->assertFalse($this->fixture->shouldRetry($message));
    }

    #[Test]
    public function isReadyForRetryWithNoLastAttempt(): void
    {
        $message = OutboxMessageBuilder::create()
            ->withStatus(OutboxStatus::Pending)
            ->withAttempts(0)
            ->withLastAttemptAt(null)
            ->build();

        $this->assertTrue($this->fixture->isReadyForRetry($message, new DateTimeImmutable()));
    }

    #[Test]
    public function isReadyForRetryAfterDelayPassed(): void
    {
        $lastAttempt = new DateTimeImmutable('2026-06-01 12:00:00');
        $now = new DateTimeImmutable('2026-06-01 12:02:00'); // 2 minutes later

        $message = OutboxMessageBuilder::create()
            ->withStatus(OutboxStatus::Pending)
            ->withAttempts(1)
            ->withLastAttemptAt($lastAttempt)
            ->build();

        $this->assertTrue($this->fixture->isReadyForRetry($message, $now));
    }

    #[Test]
    public function isNotReadyForRetryBeforeDelay(): void
    {
        $lastAttempt = new DateTimeImmutable('2026-06-01 12:00:00');
        $now = new DateTimeImmutable('2026-06-01 12:00:30'); // only 30s later

        $message = OutboxMessageBuilder::create()
            ->withStatus(OutboxStatus::Pending)
            ->withAttempts(1)
            ->withLastAttemptAt($lastAttempt)
            ->build();

        $this->assertFalse($this->fixture->isReadyForRetry($message, $now));
    }

    #[Test]
    public function isReadyForRetryAtExactDelayBoundary(): void
    {
        $lastAttempt = new DateTimeImmutable('2026-06-01 12:00:00');
        $now = new DateTimeImmutable('2026-06-01 12:01:00'); // exactly 60s (the delay) later

        $message = OutboxMessageBuilder::create()
            ->withStatus(OutboxStatus::Pending)
            ->withAttempts(1)
            ->withLastAttemptAt($lastAttempt)
            ->build();

        // now == lastAttempt + delay: ready under `>=`, not ready under `>`.
        $this->assertTrue($this->fixture->isReadyForRetry($message, $now));
    }

    #[Test]
    public function isNotReadyForRetryWhenAttemptsExhausted(): void
    {
        $message = OutboxMessageBuilder::create()
            ->withStatus(OutboxStatus::Pending)
            ->withAttempts(3) // == maxAttempts, so shouldRetry() is false
            ->withLastAttemptAt(new DateTimeImmutable('2020-01-01 00:00:00'))
            ->build();

        // The delay is long past, so only the exhausted-attempts guard keeps this false.
        $this->assertFalse($this->fixture->isReadyForRetry($message, new DateTimeImmutable('2026-06-01 12:00:00')));
    }

    #[Test]
    public function throwsOnMaxAttemptsLessThanOne(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Max attempts must be at least 1');

        new RetryPolicy(maxAttempts: 0);
    }

    #[Test]
    public function allowsMaxAttemptsOfOne(): void
    {
        $policy = new RetryPolicy(maxAttempts: 1);

        $this->assertSame(1, $policy->getMaxAttempts());
    }

    #[Test]
    public function allowsZeroDelay(): void
    {
        $policy = new RetryPolicy(delaySeconds: 0);

        $this->assertSame(0, $policy->getDelaySeconds());
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
