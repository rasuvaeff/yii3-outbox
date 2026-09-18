<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Outbox\InMemoryStorage;
use Rasuvaeff\Yii3Outbox\Outbox;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\OutboxMessageDraft;
use Rasuvaeff\Yii3Outbox\Processor;
use Rasuvaeff\Yii3Outbox\PublisherInterface;
use Rasuvaeff\Yii3Outbox\PublishException;
use Rasuvaeff\Yii3Outbox\RetryPolicy;

// Two consumers over one storage, each scoped to the types it owns; a
// publisher that declares one failure terminal; stats and a requeue after.

$clock = new class implements ClockInterface {
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
};

$storage = new InMemoryStorage();
$outbox = new Outbox(storage: $storage, clock: $clock);

// One clock read, one saveBatch() — InMemoryStorage is a BatchSavingStorageInterface.
$outbox->recordMany([
    new OutboxMessageDraft(type: 'order.created', payload: '{"orderId": 1}', aggregateId: 'order-1'),
    new OutboxMessageDraft(type: 'order.paid', payload: '{"orderId": 1}', aggregateId: 'order-1'),
    new OutboxMessageDraft(type: 'ab.exposure', payload: '{"experiment": "checkout", "variant": "b"}'),
]);

$webhooks = new class implements PublisherInterface {
    public function publish(OutboxMessage $message): void
    {
        if ($message->getType() === 'order.paid') {
            // The receiver told us it is gone for good: no retry will help.
            throw PublishException::terminal(message: 'Endpoint returned 410 Gone', outboxMessage: $message);
        }

        echo "webhook  -> {$message->getType()}\n";
    }
};

$analytics = new class implements PublisherInterface {
    public function publish(OutboxMessage $message): void
    {
        echo "analytics -> {$message->getType()}\n";
    }
};

$policy = new RetryPolicy(maxAttempts: 3, delaySeconds: 0);

$webhookProcessor = new Processor(
    storage: $storage,
    publisher: $webhooks,
    retryPolicy: $policy,
    clock: $clock,
    types: ['order.created', 'order.paid'],
);
$analyticsProcessor = new Processor(
    storage: $storage,
    publisher: $analytics,
    retryPolicy: $policy,
    clock: $clock,
    types: ['ab.exposure'],
);

$webhookProcessor->process();   // claims order.* only; ab.exposure stays Pending
$analyticsProcessor->process(); // claims ab.exposure only

$stats = $storage->stats();
echo "\npublished={$stats->published} failed={$stats->failed} pending={$stats->pending}\n";
// published=2 failed=1 pending=0 — order.paid went straight to Failed

// The endpoint is back: put the terminal failure back in line.
$moved = $outbox->requeueFailed(types: ['order.paid']);
echo "requeued={$moved}, pending now={$storage->stats()->pending}\n";
