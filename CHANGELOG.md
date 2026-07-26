# Changelog

## 1.3.0 — 2026-07-26

- Document the transactional invariant the pattern rests on: `Outbox::record()`
  must be called inside the same DB transaction as the business write, and the
  storage must use that same connection. Stated in the `StorageInterface::save()`
  and `Outbox::record()` PHPDoc, in both READMEs and in `llms.txt` — it was
  unwritten knowledge before.
- Document `claim()` as the atomic primitive `Processor` actually uses. The
  "Implementing storage" example only showed `findPending()`, so a hand-written
  storage following the README got non-atomic polling and duplicate delivery
  across concurrent workers.
- Add the missing `Processing` case to the `OutboxStatus` reference table, and
  add a `StorageInterface` section to the API reference.
- `InMemoryStorage` implements `Countable`, so `count($storage)` works next to
  the existing `count()` method.
- Declare `ext-json` in `require`: `Serializer` calls `json_encode()`/`json_decode()`.

## 1.2.0 — 2026-07-25

- `Outbox::record()` and `OutboxMessage::create()` accept an optional `id`:
  when a message mirrors a domain event, pass that event's identifier and
  republishing it cannot mint a second message id — the consumer (or the
  ClickHouse `ReplacingMergeTree` the messages land in) keeps a stable
  deduplication key.
- The generated id is now produced by `MessageIdGeneratorInterface` instead of
  being hard-coded. The default `RandomHexIdGenerator` keeps the historical
  format (32 random hex characters), so nothing changes unless a generator is
  passed; `Outbox`'s new `idGenerator` argument is last and optional.
- No UUID library is shipped or depended upon: `id` is `VARCHAR(255)` in
  `rasuvaeff/yii3-outbox-db`, so any format fits and the README shows the
  five-line generator for both symfony/uid and ramsey/uuid.
- Fix `examples/custom_storage.php`: its `StorageInterface` implementation
  still had the pre-1.1 `findPending(int $limit)` signature and no `claim()`,
  so the script fatalled on run.

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

