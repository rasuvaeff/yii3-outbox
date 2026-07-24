---
name: rasuvaeff-yii3-outbox
description: >-
  Transactional outbox pattern for Yii3 with rasuvaeff/yii3-outbox — Outbox,
  OutboxMessage, StorageInterface (save/claim/findPending), PublisherInterface,
  Processor, RetryPolicy. Use when writing, reviewing or debugging reliable
  event publishing (DB write + message send must not diverge), outbox workers,
  or retry handling in a project that has this package installed. Storage
  backends live in separate packages (e.g. rasuvaeff/yii3-outbox-db).
---

# rasuvaeff/yii3-outbox

Stateless core of the transactional outbox pattern: record messages next to
the business change, then a `Processor` publishes them with retries.
Namespace `Rasuvaeff\Yii3Outbox\`.

## Safety rules — verify these on every change

1. **Record in the SAME database transaction as the business change.** Calling
   `$outbox->record()` outside the transaction that persists the business
   entity defeats the entire pattern — a crash between the two writes loses or
   fabricates events.

2. **Delivery is at-least-once — consumers must be idempotent.** A message can
   be published twice (worker crash after publish, before `markPublished`).
   Deduplicate on the consumer side by `$message->getId()`.

3. **Concurrent workers fetch via `claim()`, never `findPending()`.**
   `claim()` atomically moves up to `$limit` `Pending` messages to
   `Processing`; `findPending()` is a read-only view and two workers would
   double-publish. Every claimed message MUST end in `markPublished()`,
   `markFailed()`, or `save($msg->withStatus(OutboxStatus::Pending))` — never
   leave a message in `Processing` indefinitely.

4. **Failed publish stays `Pending` while retries remain.** `Processor`
   increments attempts (`withAttempt($now)`) before publishing; on
   `PublishException` it saves the message back as `Pending` if
   `RetryPolicy::shouldRetry()` allows, and calls `markFailed()` only when
   attempts are exhausted. Storage's pending lookup must return messages with
   any attempt count — `RetryPolicy` decides readiness, not storage.

5. **Core never binds storage.** The core has no DB dependency; a backend
   package (`rasuvaeff/yii3-outbox-db`) or the application binds
   `StorageInterface`. `InMemoryStorage` is test-only. `OutboxMessage` is
   immutable (`withStatus()` / `withAttempt()` return new instances), and
   `Outbox`/`Processor` require an injected `Psr\Clock\ClockInterface`.

## Canonical usage

```php
use Rasuvaeff\Yii3Outbox\{Outbox, Processor, RetryPolicy};

// Inside the SAME transaction as the business change:
$message = $outbox->record(
    type: 'order.created',
    payload: json_encode(['orderId' => 42]),
    aggregateId: 'order-42', // optional
);

// Worker (cron/daemon), safe to run concurrently — uses claim():
$processor = new Processor(
    storage: $storage,
    publisher: $publisher,
    retryPolicy: new RetryPolicy(maxAttempts: 3, delaySeconds: 60),
    clock: $clock,
);
$result = $processor->process(); // ->published, ->failed, ->skipped
```

## Full API

The complete reference — `OutboxMessage` getters, `OutboxStatus` cases, the
full `StorageInterface`/`PublisherInterface` contracts, `Serializer` — ships
with the package: read `vendor/rasuvaeff/yii3-outbox/llms.txt` before guessing
a method name.
