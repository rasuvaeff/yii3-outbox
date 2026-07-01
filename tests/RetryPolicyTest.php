<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Yii3Outbox\OutboxStatus;
use Rasuvaeff\Yii3Outbox\RetryPolicy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(RetryPolicy::class)]
final class RetryPolicyTest
{
    private RetryPolicy $fixture;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->fixture = new RetryPolicy(maxAttempts: 3, delaySeconds: 60);
    }

    public function returnsConfiguredValues(): void
    {
        Assert::same($this->fixture->getMaxAttempts(), 3);
        Assert::same($this->fixture->getDelaySeconds(), 60);
    }

    public function shouldRetryWhenAttemptsNotExhausted(): void
    {
        $message = OutboxMessageBuilder::create()
            ->withStatus(OutboxStatus::Pending)
            ->withAttempts(2)
            ->build();

        Assert::true($this->fixture->shouldRetry($message));
    }

    public function shouldNotRetryWhenAttemptsExhausted(): void
    {
        $message = OutboxMessageBuilder::create()
            ->withStatus(OutboxStatus::Pending)
            ->withAttempts(3)
            ->build();

        Assert::false($this->fixture->shouldRetry($message));
    }

    public function shouldNotRetryWhenAlreadyPublished(): void
    {
        $message = OutboxMessageBuilder::create()
            ->withStatus(OutboxStatus::Published)
            ->withAttempts(0)
            ->build();

        Assert::false($this->fixture->shouldRetry($message));
    }

    public function isReadyForRetryWithNoLastAttempt(): void
    {
        $message = OutboxMessageBuilder::create()
            ->withStatus(OutboxStatus::Pending)
            ->withAttempts(0)
            ->withLastAttemptAt(null)
            ->build();

        Assert::true($this->fixture->isReadyForRetry($message, new DateTimeImmutable()));
    }

    public function isReadyForRetryAfterDelayPassed(): void
    {
        $lastAttempt = new DateTimeImmutable('2026-06-01 12:00:00');
        $now = new DateTimeImmutable('2026-06-01 12:02:00');

        $message = OutboxMessageBuilder::create()
            ->withStatus(OutboxStatus::Pending)
            ->withAttempts(1)
            ->withLastAttemptAt($lastAttempt)
            ->build();

        Assert::true($this->fixture->isReadyForRetry($message, $now));
    }

    public function isNotReadyForRetryBeforeDelay(): void
    {
        $lastAttempt = new DateTimeImmutable('2026-06-01 12:00:00');
        $now = new DateTimeImmutable('2026-06-01 12:00:30');

        $message = OutboxMessageBuilder::create()
            ->withStatus(OutboxStatus::Pending)
            ->withAttempts(1)
            ->withLastAttemptAt($lastAttempt)
            ->build();

        Assert::false($this->fixture->isReadyForRetry($message, $now));
    }

    public function isReadyForRetryAtExactDelayBoundary(): void
    {
        $lastAttempt = new DateTimeImmutable('2026-06-01 12:00:00');
        $now = new DateTimeImmutable('2026-06-01 12:01:00');

        $message = OutboxMessageBuilder::create()
            ->withStatus(OutboxStatus::Pending)
            ->withAttempts(1)
            ->withLastAttemptAt($lastAttempt)
            ->build();

        Assert::true($this->fixture->isReadyForRetry($message, $now));
    }

    public function isNotReadyForRetryWhenAttemptsExhausted(): void
    {
        $message = OutboxMessageBuilder::create()
            ->withStatus(OutboxStatus::Pending)
            ->withAttempts(3)
            ->withLastAttemptAt(new DateTimeImmutable('2020-01-01 00:00:00'))
            ->build();

        Assert::false($this->fixture->isReadyForRetry($message, new DateTimeImmutable('2026-06-01 12:00:00')));
    }

    public function throwsOnMaxAttemptsLessThanOne(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new RetryPolicy(maxAttempts: 0);
    }

    public function allowsMaxAttemptsOfOne(): void
    {
        $policy = new RetryPolicy(maxAttempts: 1);

        Assert::same($policy->getMaxAttempts(), 1);
    }

    public function allowsZeroDelay(): void
    {
        $policy = new RetryPolicy(delaySeconds: 0);

        Assert::same($policy->getDelaySeconds(), 0);
    }

    public function throwsOnNegativeDelay(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new RetryPolicy(delaySeconds: -1);
    }

    public function defaultConstructorValues(): void
    {
        $policy = new RetryPolicy();

        Assert::same($policy->getMaxAttempts(), 3);
        Assert::same($policy->getDelaySeconds(), 60);
    }

    #[Property(runs: 300)]
    public function exhaustedMessageIsNeverRetried(int $maxAttempts, int $extra): void
    {
        $policy = new RetryPolicy(maxAttempts: $maxAttempts);
        $message = OutboxMessageBuilder::create()
            ->withStatus(OutboxStatus::Pending)
            ->withAttempts($maxAttempts + $extra)
            ->build();

        Assert::false($policy->shouldRetry($message));
    }

    /** @return array<string, ArbitraryInterface> */
    private function exhaustedMessageIsNeverRetriedGenerators(): array
    {
        return [
            'maxAttempts' => Gen::intBetween(1, 8),
            'extra' => Gen::intBetween(0, 5),
        ];
    }

    #[Property(runs: 300)]
    public function pendingMessageBelowMaxIsRetried(int $attempts, int $slack): void
    {
        $maxAttempts = $attempts + $slack;
        $policy = new RetryPolicy(maxAttempts: $maxAttempts);
        $message = OutboxMessageBuilder::create()
            ->withStatus(OutboxStatus::Pending)
            ->withAttempts($attempts)
            ->build();

        Assert::true($policy->shouldRetry($message));
    }

    /** @return array<string, ArbitraryInterface> */
    private function pendingMessageBelowMaxIsRetriedGenerators(): array
    {
        return [
            'attempts' => Gen::intBetween(0, 8),
            'slack' => Gen::intBetween(1, 5),
        ];
    }
}
