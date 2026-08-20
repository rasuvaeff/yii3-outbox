<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
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
    public static function exhaustedMessageIsNeverRetriedGenerators(): array
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
    public static function pendingMessageBelowMaxIsRetriedGenerators(): array
    {
        return [
            'attempts' => Gen::intBetween(0, 8),
            'slack' => Gen::intBetween(1, 5),
        ];
    }

    public function readyThresholdSubtractsTheDelay(): void
    {
        $now = new DateTimeImmutable('2026-06-01 12:00:00');

        Assert::same(
            $this->fixture->readyThreshold($now)->format('Y-m-d H:i:s'),
            '2026-06-01 11:59:00',
        );
    }

    public function readyThresholdOfAZeroDelayPolicyIsNow(): void
    {
        $now = new DateTimeImmutable('2026-06-01 12:00:00.123456');

        Assert::same(
            (new RetryPolicy(delaySeconds: 0))->readyThreshold($now)->format('Y-m-d H:i:s.u'),
            '2026-06-01 12:00:00.123456',
        );
    }

    /**
     * The whole pushdown rests on this equivalence: a storage that filters on
     * `readyThreshold()` selects exactly the messages `isReadyForRetry()` would
     * have accepted. If the two ever disagree, a message is either attempted
     * early or never claimed at all — and only this test would notice, because
     * `Processor` runs one path and the backend the other.
     *
     * @see RetryAwareStorageInterface::claimReady()
     */
    #[Property(runs: 500)]
    public function thresholdFilterAgreesWithThePerMessageCheck(
        int $maxAttempts,
        int $attempts,
        int $delaySeconds,
        int $secondsSinceLastAttempt,
        bool $neverAttempted,
    ): void {
        $policy = new RetryPolicy(maxAttempts: $maxAttempts, delaySeconds: $delaySeconds);
        $now = new DateTimeImmutable('2026-06-01 12:00:00');
        $lastAttemptAt = $neverAttempted
            ? null
            : $now->modify('-' . $secondsSinceLastAttempt . ' seconds');

        $message = OutboxMessageBuilder::create()
            ->withStatus(OutboxStatus::Pending)
            ->withAttempts($attempts)
            ->withLastAttemptAt($lastAttemptAt)
            ->build();

        $viaThreshold = $attempts < $maxAttempts
            && ($lastAttemptAt === null || $lastAttemptAt <= $policy->readyThreshold($now));

        Classify::cover($policy->isReadyForRetry($message, $now), 'ready', 20);
        Classify::cover(!$policy->isReadyForRetry($message, $now), 'not ready', 20);
        Classify::when($lastAttemptAt === null, 'never attempted');
        Classify::when($attempts >= $maxAttempts, 'exhausted');

        Assert::same($viaThreshold, $policy->isReadyForRetry($message, $now));
    }

    /** @return array<string, ArbitraryInterface> */
    public static function thresholdFilterAgreesWithThePerMessageCheckGenerators(): array
    {
        return [
            'maxAttempts' => Gen::intBetween(1, 5),
            'attempts' => Gen::intBetween(0, 6),
            'delaySeconds' => Gen::intBetween(0, 120),
            'secondsSinceLastAttempt' => Gen::intBetween(0, 240),
            'neverAttempted' => Gen::frequency([[1, Gen::elements([true])], [4, Gen::elements([false])]]),
        ];
    }

    /**
     * The boundary is where an off-by-one lives: `isReadyForRetry()` compares
     * `now >= lastAttempt + delay`, so a message attempted exactly `delay`
     * seconds ago is ready, and one attempted a second later is not.
     *
     * @return iterable<string, array{int, int, int, int, bool}>
     */
    public static function thresholdFilterAgreesWithThePerMessageCheckExamples(): iterable
    {
        yield 'exactly at the boundary' => [3, 1, 60, 60, false];
        yield 'one second short of it' => [3, 1, 60, 59, false];
        yield 'one second past it' => [3, 1, 60, 61, false];
        yield 'zero delay, attempted now' => [3, 1, 0, 0, false];
        yield 'never attempted' => [3, 0, 60, 0, true];
        yield 'exhausted and long overdue' => [3, 3, 60, 240, false];
    }
}
