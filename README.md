# rasuvaeff/yii3-outbox

[![Stable Version](https://poser.pugx.org/rasuvaeff/yii3-outbox/v/stable)](https://packagist.org/packages/rasuvaeff/yii3-outbox)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/yii3-outbox/downloads)](https://packagist.org/packages/rasuvaeff/yii3-outbox)
[![Build](https://github.com/rasuvaeff/yii3-outbox/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/yii3-outbox/actions)
[![Static analysis](https://github.com/rasuvaeff/yii3-outbox/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/yii3-outbox/actions)
[![Coverage](https://codecov.io/gh/rasuvaeff/yii3-outbox/branch/master/graph/badge.svg)](https://codecov.io/gh/rasuvaeff/yii3-outbox)
[![Psalm Level](https://shepherd.dev/github/rasuvaeff/yii3-outbox/level.svg)](https://shepherd.dev/github/rasuvaeff/yii3-outbox)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/yii3-outbox/php)](https://packagist.org/packages/rasuvaeff/yii3-outbox)
[![License](https://poser.pugx.org/rasuvaeff/yii3-outbox/license)](https://packagist.org/packages/rasuvaeff/yii3-outbox)

Transactional outbox pattern implementation for Yii3. Provides a stateless core
for reliably publishing messages with configurable retry policies.

> Using an AI coding assistant? [llms.txt](llms.txt) has a compact API reference you can use.

## Requirements

- PHP 8.3+
- `psr/clock` ^1.0
- `psr/log` ^3.0

## Installation

```bash
composer require rasuvaeff/yii3-outbox
```

## Usage

### Recording a message

```php
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Outbox\InMemoryStorage;
use Rasuvaeff\Yii3Outbox\Outbox;

$clock = new class implements ClockInterface {
    public function now(): DateTimeImmutable { return new DateTimeImmutable(); }
};

$outbox = new Outbox(storage: $storage, clock: $clock);

$message = $outbox->record(
    type: 'order.created',
    payload: json_encode(['orderId' => 42]),
    aggregateId: 'order-42',
);
```

### Implementing storage

```php
use Rasuvaeff\Yii3Outbox\StorageInterface;
use Rasuvaeff\Yii3Outbox\OutboxMessage;

final class DbStorage implements StorageInterface
{
    public function save(OutboxMessage $message): void
    {
        // INSERT INTO outbox ... ON CONFLICT(id) DO UPDATE ...
    }

    public function findPending(int $limit = 100): array
    {
        // SELECT * FROM outbox WHERE status = 'pending' LIMIT $limit
        // For retry support, also return status = 'pending' with attempts > 0
    }

    public function markPublished(OutboxMessage $message): void
    {
        // UPDATE outbox SET status = 'published' WHERE id = ?
    }

    public function markFailed(OutboxMessage $message): void
    {
        // UPDATE outbox SET status = 'failed' WHERE id = ?
    }

    public function getById(string $id): ?OutboxMessage
    {
        // SELECT * FROM outbox WHERE id = ?
    }
}
```

### Implementing a publisher

```php
use Rasuvaeff\Yii3Outbox\PublisherInterface;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\PublishException;

final class RabbitPublisher implements PublisherInterface
{
    public function publish(OutboxMessage $message): void
    {
        try {
            // publish to RabbitMQ, Kafka, etc.
        } catch (\Throwable $e) {
            throw new PublishException(
                message: $e->getMessage(),
                outboxMessage: $message,
                previous: $e,
            );
        }
    }
}
```

### Processing the outbox

```php
use Rasuvaeff\Yii3Outbox\Processor;
use Rasuvaeff\Yii3Outbox\RetryPolicy;

$processor = new Processor(
    storage: $storage,
    publisher: $publisher,
    retryPolicy: new RetryPolicy(maxAttempts: 3, delaySeconds: 60),
    clock: $clock,
    batchSize: 100,
);

$result = $processor->process();
// $result->published — successfully published
// $result->failed   — publish exceptions (message kept Pending if retries remain)
// $result->skipped  — not yet ready for retry
```

### Retry behaviour

When a publish fails:
- If attempts < `maxAttempts` → message stays `Pending`, will be retried after `delaySeconds`
- If attempts >= `maxAttempts` → message is marked `Failed` (terminal)

```php
$policy = new RetryPolicy(maxAttempts: 3, delaySeconds: 60);

$policy->shouldRetry($message);          // bool — attempts remaining?
$policy->isReadyForRetry($message, $now); // bool — delay elapsed?
```

### Using InMemoryStorage for tests

```php
use Rasuvaeff\Yii3Outbox\InMemoryStorage;

$storage = new InMemoryStorage();
$storage->save($message);

$pending = $storage->findPending();
$storage->count();
$storage->clear();
```

## API reference

### Outbox

| Method | Description |
|---|---|
| `__construct(storage, clock)` | Main entry point |
| `record(type, payload, aggregateId?)` | Create and persist message, returns `OutboxMessage` |

### OutboxMessage

| Method | Description |
|---|---|
| `create(type, payload, aggregateId?, createdAt?)` | Factory with auto-generated ID |
| `getId()` | Message ID (32-char hex) |
| `getType()` | Message type |
| `getPayload()` | Raw payload string |
| `getStatus()` | `OutboxStatus` enum |
| `getCreatedAt()` | `DateTimeImmutable` |
| `getAttempts()` | Number of publish attempts |
| `getLastAttemptAt()` | `?DateTimeImmutable` |
| `getAggregateId()` | `?string` |
| `withStatus(status)` | Returns new instance with status |
| `withAttempt(at)` | Returns new instance with incremented attempts and timestamp |

### OutboxStatus

| Case | Value |
|---|---|
| `Pending` | `'pending'` |
| `Published` | `'published'` |
| `Failed` | `'failed'` |

### RetryPolicy

| Method | Description |
|---|---|
| `__construct(maxAttempts, delaySeconds)` | Default: 3 attempts, 60s delay |
| `shouldRetry(message)` | Checks attempt count |
| `isReadyForRetry(message, now)` | Checks attempts + delay elapsed |

### Processor

| Method | Description |
|---|---|
| `__construct(storage, publisher, retryPolicy, clock, batchSize, logger)` | Default batch: 100 |
| `process()` | Returns `ProcessingResult` |

### ProcessingResult

| Property/Method | Description |
|---|---|
| `$published` | Count of successfully published messages |
| `$failed` | Count of publish exceptions this run |
| `$skipped` | Count of messages not ready for retry |
| `total()` | Sum of all counters |

### Serializer

| Method | Description |
|---|---|
| `serialize(message)` | Message to JSON |
| `deserialize(data)` | JSON to Message |

## Security

- Storage implementations must use parameterized queries for all user values.
- Message payload is stored as-is; validate before saving if needed.

## Examples

See [examples/](examples/) for complete usage examples.

## Development

```bash
make install
make build
```

## License

BSD-3-Clause. See [LICENSE.md](LICENSE.md).
