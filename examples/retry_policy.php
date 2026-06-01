<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use DateTimeImmutable;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\OutboxStatus;
use Rasuvaeff\Yii3Outbox\RetryPolicy;

$policy = new RetryPolicy(maxAttempts: 3, delaySeconds: 60);
$now = new DateTimeImmutable();

$fresh = OutboxMessage::create(type: 'order.created', payload: '{}');
echo "Fresh message — should retry: " . ($policy->shouldRetry($fresh) ? 'yes' : 'no') . "\n";
echo "Fresh message — ready: " . ($policy->isReadyForRetry($fresh, $now) ? 'yes' : 'no') . "\n\n";

$attempted = new \Rasuvaeff\Yii3Outbox\OutboxMessage(
    id: 'msg-1',
    type: 'order.created',
    payload: '{}',
    status: OutboxStatus::Pending,
    createdAt: new DateTimeImmutable(),
    attempts: 2,
    lastAttemptAt: new DateTimeImmutable('-30 seconds'),
);
echo "2 attempts, 30s ago — should retry: " . ($policy->shouldRetry($attempted) ? 'yes' : 'no') . "\n";
echo "2 attempts, 30s ago — ready (delay=60s): " . ($policy->isReadyForRetry($attempted, $now) ? 'yes' : 'no') . "\n\n";

$afterDelay = new \Rasuvaeff\Yii3Outbox\OutboxMessage(
    id: 'msg-2',
    type: 'order.created',
    payload: '{}',
    status: OutboxStatus::Pending,
    createdAt: new DateTimeImmutable(),
    attempts: 1,
    lastAttemptAt: new DateTimeImmutable('-120 seconds'),
);
echo "1 attempt, 120s ago — ready (delay=60s): " . ($policy->isReadyForRetry($afterDelay, $now) ? 'yes' : 'no') . "\n\n";

$exhausted = new \Rasuvaeff\Yii3Outbox\OutboxMessage(
    id: 'msg-3',
    type: 'order.created',
    payload: '{}',
    status: OutboxStatus::Pending,
    createdAt: new DateTimeImmutable(),
    attempts: 3,
    lastAttemptAt: new DateTimeImmutable('-120 seconds'),
);
echo "Exhausted — should retry: " . ($policy->shouldRetry($exhausted) ? 'yes' : 'no') . "\n";
