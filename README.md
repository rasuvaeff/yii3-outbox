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

$storage = new InMemoryStorage(); // rasuvaeff/yii3-outbox-db for a real one
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

### Recording several messages at once

A request that produces five events records five messages. `recordMany()`
takes drafts — everything `record()` takes, minus what the outbox fills in —
reads the clock once so the batch is one moment in the outbox's history, and
writes them in one statement when the storage is a
`BatchSavingStorageInterface` (see [Saving a batch in one write](#saving-a-batch-in-one-write)),
one `save()` per message otherwise. Same transactional obligation as
`record()`:

```php
use Rasuvaeff\Yii3Outbox\OutboxMessageDraft;

$messages = $outbox->recordMany([
    new OutboxMessageDraft(type: 'order.created', payload: $created, aggregateId: 'order-42'),
    new OutboxMessageDraft(type: 'order.paid', payload: $paid, aggregateId: 'order-42', id: $paidEvent->getId()),
]);
// list<OutboxMessage>, in the order given; an empty list touches nothing
```

### Message priority

One outbox usually carries both bulk traffic (an HTTP journal, thousands of
rows a minute) and a few messages somebody is waiting for (an audit entry the
admin expects to see within seconds). When the sink is down and a backlog
builds up, a purely time-ordered claim drains the whole bulk backlog before
those few messages. `priority` reorders the claim:

```php
$outbox->record(type: 'audit.entry', payload: $json, priority: 10);
$outbox->record(type: 'http.exchange', payload: $json);               // priority 0
```

A storage hands out messages by `priority` descending, then `createdAt`
ascending — every eligible message of a higher priority before any of a lower
one; equal priorities keep the old time order, so an outbox that never sets a
priority behaves exactly as before. The retry policy still applies: a
high-priority message in its backoff window is not claimed.

The order is strict — there is no aging. A type that produces messages faster
than the consumer drains them would starve everything below it, so give a
priority above 0 only to low-volume types that never fill a claim on their own.
`InMemoryStorage` orders this way; `rasuvaeff/yii3-outbox-db` 2.6+ stores the
column and orders the claim.

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

### Letting the storage apply the retry policy

`claim()` returns every `Pending` message, so `Processor` receives the ones
still waiting out their backoff and writes each of them straight back as
`Pending`. That is two writes per backing-off message per iteration, and each
one occupies a slot in `batchSize` that a message ready to go could have used —
with a large retry queue, fresh messages wait behind it.

A storage that can express the predicate in its own query language implements
`RetryAwareStorageInterface`, and `Processor` claims through it automatically:

```php
use Rasuvaeff\Yii3Outbox\RetryAwareStorageInterface;

final class DbStorage implements RetryAwareStorageInterface
{
    public function claimReady(
        DateTimeImmutable $readyThreshold,
        int $maxAttempts,
        array $types = [],
        int $limit = 1000,
    ): array {
        // Same atomic claim as claim(), with one more condition:
        //   AND (attempts >= :maxAttempts
        //        OR last_attempt_at IS NULL
        //        OR last_attempt_at <= :readyThreshold)
    }

    // ... the rest of StorageInterface unchanged
}
```

The `attempts >= :maxAttempts` clause is not an optimisation and must not be
dropped. A message out of attempts cannot be retried, and the only thing left to
do with it is mark it `Failed` — which `Processor` can only do to a message the
storage handed it. Filter it out and nothing ever terminates it: it stays
`Pending`, invisible to an alert watching `Failed`, forever.

`$readyThreshold` comes from `RetryPolicy::readyThreshold($now)` — the delay is
the core's business, and an implementation must not reconstruct it. The
interface exists separately from `StorageInterface` because adding the parameter
to `claim()` itself would break every third-party implementation.

`rasuvaeff/yii3-outbox-db` implements it. A storage that does not is still
correct: `Processor` falls back to `claim()` and filters in PHP, exactly as
before.

Two consequences worth knowing before you alert on them:

- `ProcessingResult::$skipped` counts messages the batch claimed and discarded.
  Against a retry-aware storage there are none, so it reads `0` — the work it
  used to count is what this interface removes.
- A message whose attempts are spent is terminated up to `delaySeconds` later
  than before, since it now waits for a batch that includes it.

### Acknowledging a batch in one write

`markPublished()` takes one message. A consumer that delivers a batch as a
unit — one bulk insert into ClickHouse, one multi-message broker call — then
acknowledges it with one write per message, and over an SQL storage that is a
thousand statements for a thousand-message batch, all of them in the OLTP
database the application writes its business rows to.

A storage that can acknowledge many messages in one statement implements
`BatchAcknowledgingStorageInterface`, and a batching consumer detects it with
`instanceof`:

```php
use Rasuvaeff\Yii3Outbox\BatchAcknowledgingStorageInterface;

final class DbStorage implements BatchAcknowledgingStorageInterface
{
    public function markPublishedBatch(array $messages): void
    {
        // UPDATE outbox SET status = 'published', ... WHERE id IN (:ids)
    }

    // ... the rest of StorageInterface unchanged
}
```

Whether an acknowledged row is kept as `Published` or deleted outright is the
storage's decision, not the consumer's, and it must apply to `markPublished()`
as well — every producer of acknowledgements then agrees on what the table
holds. `rasuvaeff/yii3-outbox-db` implements the interface and offers both
behaviours behind a flag; `rasuvaeff/yii3-outbox-clickhouse` acknowledges
through it. `Processor` publishes one message at a time and keeps using
`markPublished()`: batching its acknowledgements would widen the window in
which a crash redelivers messages already handed to the publisher. A storage
without the interface is still correct — a batching consumer falls back to
per-message `markPublished()`.

### Saving a batch in one write

The mirror image on the recording side. `recordMany()` calls `save()` once per
message; a storage that can express the batch as one multi-row insert
implements `BatchSavingStorageInterface`, and `recordMany()` uses it:

```php
use Rasuvaeff\Yii3Outbox\BatchSavingStorageInterface;

final class DbStorage implements BatchSavingStorageInterface
{
    public function saveBatch(array $messages): void
    {
        // INSERT INTO outbox (...) VALUES (...), (...), (...)
    }
}
```

The transactional contract is the one of `save()`: through the application's
connection, inside the caller's transaction. An empty list is a no-op.

### Requeueing failed messages

A message reaches `Failed` when its attempts run out or a publisher declares
the failure terminal. Once the cause is fixed — the receiver is back, the
payload bug is deployed — an operator wants those messages published after all,
and nothing in `StorageInterface` can move a `Failed` row. A storage that can
implements `RequeueableStorageInterface`; `Outbox::requeueFailed()` drives it:

```php
$moved = $outbox->requeueFailed(types: ['order.created'], limit: 500);
// every Failed message of that type is Pending again with attempts reset;
// a storage that cannot requeue makes this throw LogicException
```

`requeue()` only moves a message the storage *currently* holds as `Failed` —
one a worker or another operator got to first is left alone and not counted.
`InMemoryStorage` and `rasuvaeff/yii3-outbox-db` implement the interface.

### Watching the backlog

`Processing` that only grows means workers die mid-batch; `Failed` that only
grows means a publisher is broken. A storage that can count cheaply implements
`StatsAwareStorageInterface` and answers with one aggregate query:

```php
use Rasuvaeff\Yii3Outbox\StatsAwareStorageInterface;

if ($storage instanceof StatsAwareStorageInterface) {
    $stats = $storage->stats();
    $stats->pending;                          // int
    $stats->processing;                       // int
    $stats->failed;                           // int
    $stats->published;                        // int
    $stats->total();                          // sum
    $stats->countOf(OutboxStatus::Failed);    // by enum case
    $stats->oldestPendingCreatedAt;           // ?DateTimeImmutable
    $stats->oldestPendingAgeSeconds($now);    // ?int — the gauge to alert on
}
```

The snapshot is a gauge, not a ledger: two calls around a concurrent write may
disagree, which is fine for a health check.

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

Every `PublishException` is retried until the `RetryPolicy` runs out of
attempts. When the publisher knows no retry can fix it — the receiver is gone
(410), the payload was rejected as malformed — it says so, and `Processor`
marks the message `Failed` at once instead of spending the remaining attempts
on delaying the alert:

```php
throw PublishException::terminal(
    message: sprintf('Endpoint %s returned 410 Gone', $endpoint),
    outboxMessage: $message,
);
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
// $result->failed   — publish failures and messages that ran out of attempts
// $result->skipped  — claimed but not yet ready for retry; always 0 against
//                     a RetryAwareStorageInterface, which never claims them
```

### Sharing a storage between consumers

`Processor` claims **every** pending message in the storage, whatever its
type. On a storage shared with another consumer that claims by type — a
`ClickHouseOutboxExporter` from `rasuvaeff/yii3-outbox-clickhouse`, a second
`Processor` with a different publisher — that is data loss: the unscoped
processor claims the other consumer's messages, its publisher does whatever it
does with a type it never expected (a webhook publisher with no endpoints for
it acknowledges silently), and the message is `Published` before the consumer
it was meant for ever sees it.

Scope each processor to the types it owns:

```php
$webhooks = new Processor(
    storage: $storage,
    publisher: $webhookPublisher,
    retryPolicy: $policy,
    clock: $clock,
    types: ['order.created', 'order.paid'],
);

$broker = new Processor(
    storage: $storage,
    publisher: $rabbitPublisher,
    retryPolicy: $policy,
    clock: $clock,
    types: ['inventory.reserved'],
);
```

The scope is forwarded to `claim()` / `claimReady()`, so a scoped processor
never even sees a foreign message. An empty scope (the default) keeps claiming
everything — right for the common case of one storage, one consumer.

### Retry behaviour

When a publish fails:
- If attempts < `maxAttempts` → message stays `Pending`, will be retried after `delaySeconds`
- If attempts >= `maxAttempts` → message is marked `Failed` (terminal)
- If the publisher threw `PublishException::terminal()` → message is marked
  `Failed` at once, whatever the attempt count; the warning log carries
  `terminal: true`

Every message a batch claims leaves `Processing`. A message that is claimed
with its attempts already spent — restored from a backup, or left behind by a
`markFailed()` that never reached the database — is marked `Failed` on sight
rather than saved back as `Pending`, which would make it circle claim → skip →
save forever with no alert on `Failed` ever firing.

A publisher that throws something other than `PublishException` is a bug, and
`process()` rethrows it: the failure is not silently retried as if it were a
delivery problem. Before the exception propagates, the current message is
persisted per the retry policy and every message the batch had claimed but not
yet attempted is released back to `Pending` (or failed, if it too was out of
attempts), so nothing is left in `Processing` for a human to find with raw SQL.

The same applies when the storage — not the publisher — is what fails. If
`markPublished()` throws after `publish()` succeeded, the message reached its
consumer and only the record of that did not: it goes back to `Pending` and a
later run publishes it again. **This package delivers at least once**, and the
message id is what a consumer deduplicates on; leaving the row `Processing`
instead would be a row nothing in this API can move. That branch logs
`Outbox message was published but could not be marked published`, not the
publisher warning — during a storage incident an operator should not be sent to
debug the publisher.

The release is best-effort by construction: it reaches for the same storage that
may be the reason the batch aborted, and a storage that is down cannot be told
anything. What it does guarantee is that its own failures are logged
(`Failed to release a claimed outbox message`) rather than thrown — the
exception you catch is always the one that aborted the batch, never a symptom
raised while reacting to it.

```php
$policy = new RetryPolicy(maxAttempts: 3, delaySeconds: 60);

$policy->shouldRetry($message);           // bool — attempts remaining?
$policy->isReadyForRetry($message, $now); // bool — delay elapsed?
$policy->readyThreshold($now);            // DateTimeImmutable — the same question
                                          // as a boundary a storage can filter on
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
| `record(type, payload, aggregateId?, id?, priority = 0)` | Create and persist message, returns `OutboxMessage`. `id` = the domain event's id; omitted → generator. `priority`: higher is claimed first — see [Message priority](#message-priority). Call inside the business transaction |
| `recordMany(list<OutboxMessageDraft>)` | Same for several messages: one clock read, one `saveBatch()` when the storage is a `BatchSavingStorageInterface`, else one `save()` each. Returns `list<OutboxMessage>` in the order given; `[]` touches nothing |
| `requeueFailed(types = [], limit = 1000)` | Moves `Failed` messages back to `Pending` with attempts reset through `RequeueableStorageInterface`; returns how many moved. `LogicException` when the storage cannot requeue |

### OutboxMessageDraft

| Property | Description |
|---|---|
| `type`, `payload`, `aggregateId?`, `id?`, `priority = 0` | What `record()` takes; `type` and `id` must not be empty. Consumed by `recordMany()` |

### StorageInterface

| Method | Description |
|---|---|
| `save(message)` | Persist. Must commit with the business write — see [The transactional guarantee](#the-transactional-guarantee) |
| `claim(types = [], limit = 1000)` | **Atomically** moves up to `limit` `Pending` messages to `Processing` and returns them, higher `priority` first, then oldest first. What `Processor` uses; safe for concurrent workers |
| `findPending(types = [], limit = 1000)` | Read-only listing of `Pending` messages. No atomicity — for dashboards, not for workers |
| `markPublished(message)` | Terminal success |
| `markFailed(message)` | Terminal failure |
| `getById(id)` | `?OutboxMessage` |

`types` filters by message type (empty = all), which is how several consumers
share one outbox. Since `claim()` hands a message to exactly one caller, the
type sets of independent consumers must not overlap — otherwise each message
reaches only whichever worker claimed it first.

### RetryAwareStorageInterface

Extends `StorageInterface`. Optional: implement it when the backend can apply
the retry policy inside the claim itself — see
[Letting the storage apply the retry policy](#letting-the-storage-apply-the-retry-policy).

| Method | Description |
|---|---|
| `claimReady(readyThreshold, maxAttempts, types = [], limit = 1000)` | Like `claim()`, but skips messages still waiting for their next attempt. Takes a message when it has never been attempted, was last attempted at or before `readyThreshold`, or has already spent `maxAttempts` attempts |

### BatchAcknowledgingStorageInterface

Extends `StorageInterface`. Optional: implement it when the backend can mark
many messages `Published` in one statement — see
[Acknowledging a batch in one write](#acknowledging-a-batch-in-one-write).

| Method | Description |
|---|---|
| `markPublishedBatch(messages)` | Marks every message in the list `Published`, as `markPublished()` would do for each, in as few writes as the backend allows. Empty list is a no-op |

### BatchSavingStorageInterface

Extends `StorageInterface`. Optional: implement it when the backend can insert
many rows in one statement — see [Saving a batch in one write](#saving-a-batch-in-one-write).

| Method | Description |
|---|---|
| `saveBatch(messages)` | Persists every message under the `save()` contract. Empty list is a no-op |

### RequeueableStorageInterface

Extends `StorageInterface`. Optional — see [Requeueing failed messages](#requeueing-failed-messages).

| Method | Description |
|---|---|
| `findFailed(types = [], limit = 1000)` | `Failed` messages, oldest first where the backend keeps an order |
| `requeue(message)` | `Failed` → `Pending` with attempts reset (`OutboxMessage::withAttemptsReset()`). Returns `false`, touching nothing, when the storage no longer holds the message as `Failed` |

### StatsAwareStorageInterface

Extends `StorageInterface`. Optional — see [Watching the backlog](#watching-the-backlog).

| Method | Description |
|---|---|
| `stats()` | An `OutboxStats` snapshot |

### OutboxStats

| Property/Method | Description |
|---|---|
| `$pending`, `$processing`, `$published`, `$failed` | Counts; each non-negative |
| `$oldestPendingCreatedAt` | `?DateTimeImmutable`; `null` when nothing is pending or the backend does not track it |
| `total()` | Sum of the four counts |
| `countOf(status)` | The count for an `OutboxStatus` case |
| `oldestPendingAgeSeconds(now)` | Seconds since the oldest pending message was created, never negative; `null` without a timestamp |

### OutboxMessage

| Method | Description |
|---|---|
| `create(type, payload, aggregateId?, createdAt?, id?, priority = 0)` | Factory; `id` omitted → 32-char hex |
| `getId()` | Message ID (32-char hex) |
| `getType()` | Message type |
| `getPayload()` | Raw payload string |
| `getStatus()` | `OutboxStatus` enum |
| `getCreatedAt()` | `DateTimeImmutable` |
| `getAttempts()` | Number of publish attempts |
| `getLastAttemptAt()` | `?DateTimeImmutable` |
| `getAggregateId()` | `?string` |
| `getPriority()` | `int`, default 0; higher is claimed first. Kept by every `with*()` |
| `withStatus(status)` | Returns new instance with status |
| `withAttempt(at)` | Returns new instance with incremented attempts and timestamp |
| `withAttemptsReset()` | Returns new instance as never attempted: `Pending`, zero attempts, no last attempt. What a requeue stores |

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
| `Failed` | `'failed'` | Terminal failure: retries exhausted or declared terminal by the publisher. Movable only by a requeue |

### RetryPolicy

| Method | Description |
|---|---|
| `__construct(maxAttempts, delaySeconds)` | Default: 3 attempts, 60s delay |
| `shouldRetry(message)` | Checks attempt count |
| `isReadyForRetry(message, now)` | Checks attempts + delay elapsed |
| `readyThreshold(now)` | `now - delaySeconds`: the same check as a boundary a storage can filter on. Feeds `RetryAwareStorageInterface::claimReady()` |

### Processor

| Method | Description |
|---|---|
| `__construct(storage, publisher, retryPolicy, clock, batchSize, logger, types)` | Default batch: 100. `types` (`list<string>`, default `[]` = every type) scopes what this processor claims — see [Sharing a storage between consumers](#sharing-a-storage-between-consumers) |
| `process()` | Returns `ProcessingResult` |

### PublishException

| Method | Description |
|---|---|
| `__construct(message, outboxMessage, code = 0, previous = null, terminal = false)` | What a publisher throws on a delivery failure; retried per `RetryPolicy` |
| `terminal(message, outboxMessage, code = 0, previous = null)` | Static factory for a failure no retry can fix; `Processor` marks the message `Failed` at once |
| `getOutboxMessage()`, `isTerminal()` | Accessors |

### ProcessingResult

| Property/Method | Description |
|---|---|
| `$published` | Count of successfully published messages |
| `$failed` | Count of publish failures this run, plus messages claimed with no attempts left |
| `$skipped` | Count of claimed messages not ready for retry. `0` against a `RetryAwareStorageInterface`, which never claims them |
| `total()` | Sum of all counters |

### Serializer

`Serializer` implements `SerializerInterface` — the extension point a storage
backend or transport uses to move a message across a boundary as a string. Swap
in your own implementation for a different wire format; the interface is the
contract, `Serializer` is the JSON default.

| Method | Description |
|---|---|
| `serialize(message)` | Message to JSON |
| `deserialize(data)` | JSON to Message |

`deserialize()` rejects every malformed input with `InvalidArgumentException` —
missing fields, wrong types, an unknown status, an unparsable or empty
datetime. A caller catching "bad input" never has to also catch `ValueError` or
`DateMalformedStringException` from a field the parser forgot to guard.

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
