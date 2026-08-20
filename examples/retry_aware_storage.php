<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Outbox\InMemoryStorage;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\OutboxStatus;
use Rasuvaeff\Yii3Outbox\Processor;
use Rasuvaeff\Yii3Outbox\PublisherInterface;
use Rasuvaeff\Yii3Outbox\RetryAwareStorageInterface;
use Rasuvaeff\Yii3Outbox\RetryPolicy;
use Rasuvaeff\Yii3Outbox\StorageInterface;

$now = new DateTimeImmutable('2026-06-01 12:00:00');
$policy = new RetryPolicy(maxAttempts: 3, delaySeconds: 60);

$clock = new class ($now) implements ClockInterface {
    public function __construct(private readonly DateTimeImmutable $now) {}

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
};

$publisher = new class implements PublisherInterface {
    public function publish(OutboxMessage $message): void {}
};

/**
 * Nine messages waiting out their backoff, one ready to go.
 */
$seed = static function (StorageInterface $storage) use ($now): void {
    for ($i = 1; $i <= 9; $i++) {
        $storage->save(new OutboxMessage(
            id: 'backing-off-' . $i,
            type: 'order.created',
            payload: '{}',
            status: OutboxStatus::Pending,
            createdAt: $now,
            attempts: 1,
            lastAttemptAt: $now->modify('-30 seconds'),
        ));
    }

    $storage->save(new OutboxMessage(
        id: 'ready',
        type: 'order.created',
        payload: '{}',
        status: OutboxStatus::Pending,
        createdAt: $now,
    ));
};

// A plain StorageInterface: Processor claims everything and writes the
// backing-off messages straight back.
$plain = new class implements StorageInterface {
    public int $writes = 0;

    public bool $counting = false;

    private InMemoryStorage $inner;

    public function __construct()
    {
        $this->inner = new InMemoryStorage();
    }

    public function save(OutboxMessage $message): void
    {
        if ($this->counting) {
            $this->writes++;
        }

        $this->inner->save($message);
    }

    public function findPending(array $types = [], int $limit = 1000): array
    {
        return $this->inner->findPending($types, $limit);
    }

    public function claim(array $types = [], int $limit = 1000): array
    {
        return $this->inner->claim($types, $limit);
    }

    public function markPublished(OutboxMessage $message): void
    {
        $this->inner->markPublished($message);
    }

    public function markFailed(OutboxMessage $message): void
    {
        $this->inner->markFailed($message);
    }

    public function getById(string $id): ?OutboxMessage
    {
        return $this->inner->getById($id);
    }
};

// The same storage, able to apply the readiness predicate itself.
$retryAware = new class implements RetryAwareStorageInterface {
    public int $writes = 0;

    public bool $counting = false;

    private InMemoryStorage $inner;

    public function __construct()
    {
        $this->inner = new InMemoryStorage();
    }

    public function save(OutboxMessage $message): void
    {
        if ($this->counting) {
            $this->writes++;
        }

        $this->inner->save($message);
    }

    public function findPending(array $types = [], int $limit = 1000): array
    {
        return $this->inner->findPending($types, $limit);
    }

    public function claim(array $types = [], int $limit = 1000): array
    {
        return $this->inner->claim($types, $limit);
    }

    public function claimReady(
        DateTimeImmutable $readyThreshold,
        int $maxAttempts,
        array $types = [],
        int $limit = 1000,
    ): array {
        // A real backend puts this in the WHERE clause; InMemoryStorage
        // already implements the same predicate.
        return $this->inner->claimReady($readyThreshold, $maxAttempts, $types, $limit);
    }

    public function markPublished(OutboxMessage $message): void
    {
        $this->inner->markPublished($message);
    }

    public function markFailed(OutboxMessage $message): void
    {
        $this->inner->markFailed($message);
    }

    public function getById(string $id): ?OutboxMessage
    {
        return $this->inner->getById($id);
    }
};

foreach (['plain StorageInterface' => $plain, 'RetryAwareStorageInterface' => $retryAware] as $label => $storage) {
    $seed($storage);
    $storage->counting = true;

    $result = (new Processor(
        storage: $storage,
        publisher: $publisher,
        retryPolicy: $policy,
        clock: $clock,
        batchSize: 5,
    ))->process();

    echo $label . "\n";
    echo '  published:   ' . $result->published . "\n";
    echo '  skipped:     ' . $result->skipped . "\n";
    echo '  write-backs: ' . $storage->writes . "\n\n";
}

echo "The plain storage spends its whole batch on messages it cannot publish yet,\n";
echo "writing every one of them back. The retry-aware one never claims them, so\n";
echo "the ready message fits in the batch and nothing is written back.\n\n";

echo 'Threshold fed to claimReady(): ' . $policy->readyThreshold($now)->format('Y-m-d H:i:s') . "\n";
