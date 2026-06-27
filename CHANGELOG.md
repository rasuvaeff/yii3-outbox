# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.0.1 — 2026-06-27

- Migrate test suite from PHPUnit to Testo. Internal change, no public API impact.

## 1.0.0 — 2026-06-12

- `Outbox` facade — records messages via `record(type, payload, aggregateId?)`.
- `OutboxMessage` immutable value object with `withStatus()` and `withAttempt()` modifiers.
- `OutboxStatus` enum: `Pending`, `Published`, `Failed`.
- `StorageInterface` and `PublisherInterface` contracts for adapter implementations. `findPending(array $types = [], int $limit = 1000)` filters by message type so several consumers can share one outbox.
- `Processor` — fetches pending messages, publishes them, handles retries; returns `ProcessingResult`.
- `RetryPolicy` — configurable `maxAttempts` and `delaySeconds`.
- `Serializer` — JSON serialization of `OutboxMessage` for transport.
- `InMemoryStorage` — test-only storage implementation.
- DB storage deferred to `yii3-outbox-db`.

