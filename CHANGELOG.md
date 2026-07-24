# Changelog

## 1.1.0 — 2026-07-25

- Ship an AI agent skill (`resources/skills/rasuvaeff-yii3-outbox/SKILL.md` +
  `extra.skills` in composer.json): projects using the `llm/skills` Composer
  plugin get the skill synced into `.agents/skills/` automatically on install.
- Document `StorageInterface::claim()` and `OutboxStatus::Processing` in `llms.txt`.
- Bump `rasuvaeff/property-testing` to `^2.6`.
- Make property-test generator methods `public static` (private ones are removed by rector's `RemoveUnusedPrivateMethodRector` — they are only called via reflection).

## 1.0.2 — 2026-06-30

- Add `/benchmarks` and `/Makefile` to `.gitattributes` export-ignore.
- Pin `testo/bridge-infection` to `0.1.6`: 0.1.7/0.1.8 (2026-06-29) misclassify failing tests as passed under mutants, producing false escapes in mutation testing.

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

