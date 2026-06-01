<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Outbox\InMemoryStorage;
use Rasuvaeff\Yii3Outbox\Outbox;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\Processor;
use Rasuvaeff\Yii3Outbox\PublisherInterface;
use Rasuvaeff\Yii3Outbox\RetryPolicy;

$clock = new class implements ClockInterface {
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
};

$storage = new InMemoryStorage();
$outbox = new Outbox(storage: $storage, clock: $clock);

$message = $outbox->record(
    type: 'order.created',
    payload: '{"orderId": 42, "total": 99.95}',
    aggregateId: 'order-42',
);

echo "Saved message: {$message->getId()}\n";
echo "Type: {$message->getType()}\n";
echo "Aggregate: {$message->getAggregateId()}\n";
echo "Status: {$message->getStatus()->value}\n\n";

$publisher = new class implements PublisherInterface {
    public function publish(OutboxMessage $message): void
    {
        echo "Publishing: {$message->getType()} ({$message->getId()})\n";
    }
};

$processor = new Processor(
    storage: $storage,
    publisher: $publisher,
    retryPolicy: new RetryPolicy(maxAttempts: 3, delaySeconds: 0),
    clock: $clock,
);

$result = $processor->process();

echo "\nPublished: {$result->published}, Failed: {$result->failed}, Skipped: {$result->skipped}\n";

$updated = $storage->getById($message->getId());
echo "Final status: {$updated->getStatus()->value}\n";
