# rasuvaeff/yii3-outbox

[![Stable Version](https://poser.pugx.org/rasuvaeff/yii3-outbox/v/stable)](https://packagist.org/packages/rasuvaeff/yii3-outbox)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/yii3-outbox/downloads)](https://packagist.org/packages/rasuvaeff/yii3-outbox)
[![Build](https://github.com/rasuvaeff/yii3-outbox/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/yii3-outbox/actions)
[![Static analysis](https://github.com/rasuvaeff/yii3-outbox/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/yii3-outbox/actions)
[![Psalm Level](https://shepherd.dev/github/rasuvaeff/yii3-outbox/level.svg)](https://shepherd.dev/github/rasuvaeff/yii3-outbox)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/yii3-outbox/php)](https://packagist.org/packages/rasuvaeff/yii3-outbox)
[![License](https://poser.pugx.org/rasuvaeff/yii3-outbox/license)](https://packagist.org/packages/rasuvaeff/yii3-outbox)
[Русская версия](README.ru.md)

Transactional outbox pattern implementation for Yii3. Provides a stateless core
for reliably publishing messages with configurable retry policies.

> Using an AI coding assistant? [llms.txt](llms.txt) has a compact API reference you can use.
> Projects using the [llm/skills](https://github.com/roxblnfk/skills) Composer plugin also get this package's agent skill synced into `.agents/skills/` automatically on install.

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

### The transactional guarantee

The pattern is only worth its name when the outbox write commits **atomically
with the business write it describes**. The core cannot enforce this: it opens
no transaction and knows nothing about your connection. Two obligations are
therefore yours:

1. Call `record()` inside the same database transaction as the business write.
2. Use a storage that writes through the same connection as your business
   tables — `rasuvaeff/yii3-outbox-db` takes a `ConnectionInterface` for
   exactly this reason.

```php
$db->transaction(static function () use ($orders, $outbox, $order, $json): void {
    $orders->insert($order);

    $outbox->record(
        type: 'order.created',
        payload: $json,
        aggregateId: $order->id,
    );
});
```

Break either obligation and the guarantee is void: commit the order without the
message and the event is lost forever; commit the message without the order and
consumers observe an event that never happened. A storage backed by a different
database — or by a message broker — cannot provide this guarantee at all, and
`InMemoryStorage` is a test double, not a durable one.

### Message ids

The message id is the primary key of the outbox table and, when messages are
exported to ClickHouse, the deduplication key of the `ReplacingMergeTree`.
Two ways to control it:

**Pass the domain event's id** — the right choice whenever the message mirrors
an event that already has an identifier. Republishing the same event then
cannot mint a second id, so the consumer has something stable to deduplicate
on:

```php
$outbox->record(
    type: 'order.created',
    payload: $json,
    aggregateId: 'order-42',
    id: $domainEvent->getId(),
);
```

**Bind a generator** for messages that have no domain id. The default
`RandomHexIdGenerator` keeps the historical format (32 random hex characters);
a time-ordered id makes inserts append instead of scattering across InnoDB
pages and gives batches a stable order:

```php
use Rasuvaeff\Yii3Outbox\MessageIdGeneratorInterface;

// symfony/uid
final readonly class Uuid7IdGenerator implements MessageIdGeneratorInterface
{
    public function generate(): string
    {
        return \Symfony\Component\Uid\Uuid::v7()->toRfc4122();
    }
}

// ramsey/uuid — equally monotonic within the same millisecond
final readonly class RamseyUuid7IdGenerator implements MessageIdGeneratorInterface
{
    public function generate(): string
    {
        return \Ramsey\Uuid\Uuid::uuid7()->toString();
    }
}

$outbox = new Outbox(storage: $storage, clock: $clock, idGenerator: new Uuid7IdGenerator());
```

The package ships no UUID implementation and depends on no UUID library —
`id` is `VARCHAR(255)` in `rasuvaeff/yii3-outbox-db`, so any format fits and
the choice stays yours.

### Implementing storage

`claim()` is the primitive the whole polling loop rests on — `Processor` calls
it, never `findPending()`. It must atomically move messages to `Processing` and
return them, so that two workers polling the same table never receive the same
message. `findPending()` is the read-only counterpart: safe for dashboards and
diagnostics, unsafe as a worker's fetch.

```php
use Rasuvaeff\Yii3Outbox\StorageInterface;
use Rasuvaeff\Yii3Outbox\OutboxMessage;

final class DbStorage implements StorageInterface
{
    public function save(OutboxMessage $message): void
    {
        // INSERT INTO outbox ... ON CONFLICT(id) DO UPDATE ...
        // Must run on the caller's connection so it commits with the business write.
    }

    public function claim(array $types = [], int $limit = 1000): array
    {
        // Atomically: SELECT ids of status = 'pending' [AND type IN (:types)]
        //   LIMIT :limit FOR UPDATE SKIP LOCKED
        // then UPDATE outbox SET status = 'processing', claimed_by = :worker
        //   WHERE id IN (...) — and return the claimed rows.
        // Every claimed message must end up markPublished(), markFailed(),
        // or save($msg->withStatus(Pending)); none may stay Processing.
    }

    public function findPending(array $types = [], int $limit = 1000): array
    {
        // SELECT * FROM outbox WHERE status = 'pending'
        //   [AND type IN (:types)] LIMIT :limit  -- empty $types = all types
        // Read-only: no atomicity, so two workers would both get the same rows.
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
| `__construct(storage, clock, idGenerator?)` | Main entry point; default generator = `RandomHexIdGenerator` |
| `record(type, payload, aggregateId?, id?)` | Create and persist message, returns `OutboxMessage`. `id` = the domain event's id; omitted → generator. Call inside the business transaction |

### StorageInterface

| Method | Description |
|---|---|
| `save(message)` | Persist. Must commit with the business write — see [The transactional guarantee](#the-transactional-guarantee) |
| `claim(types = [], limit = 1000)` | **Atomically** moves up to `limit` `Pending` messages to `Processing` and returns them. What `Processor` uses; safe for concurrent workers |
| `findPending(types = [], limit = 1000)` | Read-only listing of `Pending` messages. No atomicity — for dashboards, not for workers |
| `markPublished(message)` | Terminal success |
| `markFailed(message)` | Terminal failure |
| `getById(id)` | `?OutboxMessage` |

`types` filters by message type (empty = all), which is how several consumers
share one outbox. Since `claim()` hands a message to exactly one caller, the
type sets of independent consumers must not overlap — otherwise each message
reaches only whichever worker claimed it first.

### OutboxMessage

| Method | Description |
|---|---|
| `create(type, payload, aggregateId?, createdAt?, id?)` | Factory; `id` omitted → 32-char hex |
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

### MessageIdGeneratorInterface

| Implementation | Produces |
|---|---|
| `RandomHexIdGenerator` (default) | 32 hex characters, 128 random bits |
| your own | anything non-empty; `id` is `VARCHAR(255)` in the DB adapter |

### OutboxStatus

| Case | Value | Meaning |
|---|---|---|
| `Pending` | `'pending'` | Awaiting publication, including retries with `attempts > 0` |
| `Processing` | `'processing'` | Claimed by a worker; no other worker may take it |
| `Published` | `'published'` | Terminal success |
| `Failed` | `'failed'` | Terminal failure, retries exhausted |

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
make cs-fix
make test
make test-coverage
make mutation
make release-check
```

`make test-coverage` and `make mutation` bootstrap `pcov` inside the
`composer:2` container because the base image has no coverage driver.

## License

BSD-3-Clause. See [LICENSE.md](LICENSE.md).
