# rasuvaeff/yii3-outbox

[![Stable Version](https://poser.pugx.org/rasuvaeff/yii3-outbox/v/stable)](https://packagist.org/packages/rasuvaeff/yii3-outbox)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/yii3-outbox/downloads)](https://packagist.org/packages/rasuvaeff/yii3-outbox)
[![Build](https://github.com/rasuvaeff/yii3-outbox/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/yii3-outbox/actions)
[![Static analysis](https://github.com/rasuvaeff/yii3-outbox/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/yii3-outbox/actions)
[![Coverage](https://codecov.io/gh/rasuvaeff/yii3-outbox/branch/master/graph/badge.svg)](https://codecov.io/gh/rasuvaeff/yii3-outbox)
[![Psalm Level](https://shepherd.dev/github/rasuvaeff/yii3-outbox/level.svg)](https://shepherd.dev/github/rasuvaeff/yii3-outbox)
[![License](https://poser.pugx.org/rasuvaeff/yii3-outbox/license)](https://packagist.org/packages/rasuvaeff/yii3-outbox)

Transactional outbox pattern implementation for Yii3. Provides a stateless core
for reliably publishing messages with configurable retry policies.

> Using an AI coding assistant? [llms.txt](llms.txt) has a compact API reference you can use.

## Requirements

- PHP 8.3+
- `psr/log` ^3.0

## Installation

```bash
composer require rasuvaeff/yii3-outbox
```

## Usage

### Creating a message

```php
use Rasuvaeff\Yii3Outbox\OutboxMessage;

$message = OutboxMessage::create(
    type: 'order.created',
    payload: '{"orderId": 42}',
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
        // INSERT INTO outbox ...
    }

    public function findPending(int $limit = 100): array
    {
        // SELECT * FROM outbox WHERE status = 'pending' LIMIT $limit
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
    batchSize: 100,
);

$processed = $processor->process();
```

### Using InMemoryStorage for tests

```php
use Rasuvaeff\Yii3Outbox\InMemoryStorage;

$storage = new InMemoryStorage();
$storage->save($message);

$pending = $storage->findPending();
```

## API reference

### OutboxMessage

| Method | Description |
|---|---|
| `create(type, payload)` | Factory with auto-generated ID |
| `getId()` | Message ID (32-char hex) |
| `getType()` | Message type |
| `getPayload()` | Raw payload string |
| `getStatus()` | `OutboxStatus` enum |
| `getCreatedAt()` | `DateTimeImmutable` |
| `getAttempts()` | Number of publish attempts |
| `getLastAttemptAt()` | `?DateTimeImmutable` |
| `withStatus(status)` | Returns new instance with status |
| `withAttempt()` | Returns new instance with incremented attempts |

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
| `isReadyForRetry(message)` | Checks attempts + delay elapsed |

### Processor

| Method | Description |
|---|---|
| `__construct(storage, publisher, retryPolicy, batchSize, logger)` | Default batch: 100 |
| `process()` | Returns number of processed messages |

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
