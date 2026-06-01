<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Rasuvaeff\Yii3Outbox\OutboxMessageBuilder;
use Rasuvaeff\Yii3Outbox\OutboxStatus;
use Rasuvaeff\Yii3Outbox\RetryPolicy;

$policy = new RetryPolicy(maxAttempts: 3, delaySeconds: 60);

$message = OutboxMessageBuilder::create()
    ->withStatus(OutboxStatus::Pending)
    ->withAttempts(2)
    ->build();

echo "Should retry: " . ($policy->shouldRetry($message) ? 'yes' : 'no') . "\n";
echo "Attempts: {$message->getAttempts()}/{$policy->getMaxAttempts()}\n";

$exhausted = OutboxMessageBuilder::create()
    ->withStatus(OutboxStatus::Pending)
    ->withAttempts(3)
    ->build();

echo "\nExhausted should retry: " . ($policy->shouldRetry($exhausted) ? 'yes' : 'no') . "\n";
