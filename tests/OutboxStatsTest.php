<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Yii3Outbox\OutboxStats;
use Rasuvaeff\Yii3Outbox\OutboxStatus;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(OutboxStats::class)]
final class OutboxStatsTest
{
    public function exposesEveryCount(): void
    {
        $oldest = new DateTimeImmutable('2026-06-01 10:00:00');
        $stats = new OutboxStats(pending: 1, processing: 2, published: 3, failed: 4, oldestPendingCreatedAt: $oldest);

        Assert::same($stats->pending, 1);
        Assert::same($stats->processing, 2);
        Assert::same($stats->published, 3);
        Assert::same($stats->failed, 4);
        Assert::same($stats->oldestPendingCreatedAt, $oldest);
        Assert::same($stats->total(), 10);
    }

    public function oldestPendingDefaultsToNull(): void
    {
        Assert::null((new OutboxStats(pending: 0, processing: 0, published: 0, failed: 0))->oldestPendingCreatedAt);
    }

    #[DataProvider('countProvider')]
    public function countOfMapsEachStatus(OutboxStatus $status, int $expected): void
    {
        $stats = new OutboxStats(pending: 1, processing: 2, published: 3, failed: 4, oldestPendingCreatedAt: new DateTimeImmutable());

        Assert::same($stats->countOf($status), $expected);
    }

    public static function countProvider(): iterable
    {
        yield 'pending' => [OutboxStatus::Pending, 1];
        yield 'processing' => [OutboxStatus::Processing, 2];
        yield 'published' => [OutboxStatus::Published, 3];
        yield 'failed' => [OutboxStatus::Failed, 4];
    }

    #[DataProvider('negativeCountProvider')]
    public function rejectsANegativeCount(string $name, int $pending, int $processing, int $published, int $failed): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessage(sprintf('Count "%s" must be non-negative, got -1', $name));

        new OutboxStats(pending: $pending, processing: $processing, published: $published, failed: $failed);
    }

    public static function negativeCountProvider(): iterable
    {
        yield 'pending' => ['pending', -1, 0, 0, 0];
        yield 'processing' => ['processing', 0, -1, 0, 0];
        yield 'published' => ['published', 0, 0, -1, 0];
        yield 'failed' => ['failed', 0, 0, 0, -1];
    }

    public function rejectsAnOldestPendingTimestampWithoutPendingMessages(): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessage('Oldest pending timestamp requires at least one pending message');

        new OutboxStats(pending: 0, processing: 0, published: 0, failed: 0, oldestPendingCreatedAt: new DateTimeImmutable());
    }

    public function allowsPendingMessagesWithoutAnOldestTimestamp(): void
    {
        // A backend that counts but does not track the oldest row is allowed
        // to say so with null.
        Assert::null((new OutboxStats(pending: 3, processing: 0, published: 0, failed: 0))->oldestPendingCreatedAt);
    }

    public function oldestPendingAgeIsNullWithoutPendingMessages(): void
    {
        $stats = new OutboxStats(pending: 0, processing: 0, published: 0, failed: 0);

        Assert::null($stats->oldestPendingAgeSeconds(new DateTimeImmutable()));
    }

    public function oldestPendingAgeIsMeasuredAtTheGivenMoment(): void
    {
        $stats = new OutboxStats(
            pending: 1,
            processing: 0,
            published: 0,
            failed: 0,
            oldestPendingCreatedAt: new DateTimeImmutable('2026-06-01 10:00:00'),
        );

        Assert::same($stats->oldestPendingAgeSeconds(new DateTimeImmutable('2026-06-01 10:01:30')), 90);
        Assert::same($stats->oldestPendingAgeSeconds(new DateTimeImmutable('2026-06-01 10:00:00')), 0);
    }

    public function oldestPendingAgeNeverGoesNegativeOnClockSkew(): void
    {
        $stats = new OutboxStats(
            pending: 1,
            processing: 0,
            published: 0,
            failed: 0,
            oldestPendingCreatedAt: new DateTimeImmutable('2026-06-01 10:00:00'),
        );

        Assert::same($stats->oldestPendingAgeSeconds(new DateTimeImmutable('2026-06-01 09:59:00')), 0);
    }

    #[Property(runs: 200)]
    public function totalIsTheSumOfCountOfOverEveryStatus(int $pending, int $processing, int $published, int $failed): void
    {
        $stats = new OutboxStats(pending: $pending, processing: $processing, published: $published, failed: $failed);

        $sum = 0;
        foreach (OutboxStatus::cases() as $status) {
            $sum += $stats->countOf($status);
        }

        Assert::same($stats->total(), $sum);
        Assert::same($stats->total(), $pending + $processing + $published + $failed);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function totalIsTheSumOfCountOfOverEveryStatusGenerators(): array
    {
        return [
            'pending' => Gen::intBetween(0, 1_000_000),
            'processing' => Gen::intBetween(0, 1_000_000),
            'published' => Gen::intBetween(0, 1_000_000),
            'failed' => Gen::intBetween(0, 1_000_000),
        ];
    }
}
