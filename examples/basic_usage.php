<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Rasuvaeff\Yii3Outbox\InMemoryStorage;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\OutboxStatus;
use Rasuvaeff\Yii3Outbox\Processor;
use Rasuvaeff\Yii3Outbox\PublisherInterface;
use Rasuvaeff\Yii3Outbox\PublishException;
use Rasuvaeff\Yii3Outbox\RetryPolicy;

$storage = new InMemoryStorage();

$message = OutboxMessage::create(
    type: 'order.created',
    payload: '{"orderId": 42, "total": 99.95}',
);

$storage->save($message);

echo "Saved message: {$message->getId()}\n";
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
);

$processed = $processor->process();

echo "\nProcessed: {$processed}\n";

$updated = $storage->getById($message->getId());
echo "Final status: {$updated->getStatus()->value}\n";
