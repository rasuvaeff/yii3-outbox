# Changelog

## Unreleased

- Tests build their doubles with `rasuvaeff/understudy-testo` instead of
  hand-written fake classes: `SpyLogger`, `StubPublisher`, `StubClock`,
  `FixedIdGenerator`, `FailingStorage` and `RecordingStorage` are gone. The
  storage decorators became `Understudy::delegate()` doubles over the real
  `InMemoryStorage` (a configured `throws()` wins, everything else forwards and
  is recorded), the publisher's `$publishedIds`/`$lastPublished` counters read
  the call log, and the logger assertions claim their counts with
  `verify(..., times:)`. Rector catches up with its current rule set in `src/`
  and the tests. Dev-dependency only; the public contract is untouched.

## 1.5.0 — 2026-08-20

### Added

- `RetryAwareStorageInterface`, an optional extension of `StorageInterface` that
  a backend implements when it can apply the retry policy inside the claim
  itself, and `RetryPolicy::readyThreshold()`, which turns the per-message
  `isReadyForRetry()` check into the boundary such a backend filters on.
  `Processor` detects the interface and claims through `claimReady()`; a storage
  without it keeps working unchanged
  ([#20](https://github.com/rasuvaeff/yii3-outbox/issues/20)).

  Until now every `Pending` message was claimed and the ones still waiting out
  their backoff were written straight back as `Pending` — two writes per
  backing-off message per worker iteration, each occupying a slot in `batchSize`
  that a ready message could have used, so delivery latency grew with the size
  of the retry queue. `rasuvaeff/yii3-outbox-db` implements the interface; a
  hand-written storage does not have to.

  An implementation must keep claiming messages whose attempts are spent, in or
  out of the backoff window: only `markFailed()` terminates one, and `Processor`
  can only fail a message it was given. That is what the `maxAttempts` argument
  is for, and dropping it strands those rows as `Pending` forever.

### Changed

- `ProcessingResult::$skipped` reads `0` against a retry-aware storage. It
  counts messages a batch claimed and then discarded, and the pushdown means
  there are none — the work it used to count is what this release removes. A
  dashboard or alert reading `$skipped` as "how many are backing off" was
  already measuring wasted effort rather than queue depth, and now measures
  nothing; count `Pending` rows with a future retry time instead.
- A message that arrives with no attempts left is terminated up to
  `delaySeconds` later than before, since it now waits for a batch that
  includes it.
- `Processor::process()` reads the clock before claiming rather than after, so
  one instant serves both the threshold it sends to the storage and the
  per-message check it runs on the result — the two cannot disagree about what
  "now" is. With a slow claim query `lastAttemptAt` is stamped marginally
  earlier than the attempt itself, which makes the next retry marginally
  earlier too.
- `InMemoryStorage` implements `RetryAwareStorageInterface`.

## 1.4.0 — 2026-08-20

### Fixed

- `Processor` no longer strands messages in `Processing`. Only `PublishException`
  was caught, so anything else a publisher let escape — a transport exception it
  forgot to wrap, a `TypeError` — left every message the batch had claimed stuck
  in `Processing` with no API able to move it back. The unexpected exception is
  still rethrown (it is a bug, not a delivery failure), but the current message
  is persisted per the retry policy and the rest of the claimed batch is
  released first
  ([#18](https://github.com/rasuvaeff/yii3-outbox/issues/18)).
- A storage failure is no longer reported as a publisher failure. `markPublished()`
  ran inside the publisher's `try`, so throwing there after a successful
  `publish()` logged `Outbox publisher threw an unexpected exception` and sent an
  operator to debug the wrong component. The two calls now have separate
  handlers; the message still goes back to `Pending` and is published again on a
  later run, which is the at-least-once behaviour the message id exists to let
  consumers deduplicate.
- Releasing an aborted batch no longer swallows the exception that aborted it.
  `release()` reaches for the same storage that may be the reason the batch
  failed; its `save()` throwing replaced the original exception and left the
  remaining messages unreleased. Each message is now released independently and
  a release failure is logged (`Failed to release a claimed outbox message`,
  `Failed to persist an outbox message while aborting the batch`) rather than
  thrown. The invariant is documented for what it is: best-effort, since a
  storage that is down cannot be told anything.
- A `Pending` message claimed with its attempts already spent is marked `Failed`
  instead of being saved back as `Pending`. It could not be retried and nothing
  else would ever terminate it, so it circled claim → skip → save forever while
  an alert on `Failed` saw nothing
  ([#18](https://github.com/rasuvaeff/yii3-outbox/issues/18)).
- `Serializer::deserialize()` now reports malformed dates and unknown statuses
  as `InvalidArgumentException` like every other rejection in the class, instead
  of letting a raw `DateMalformedStringException` or `ValueError` escape. An
  empty datetime string — a valid "now" for `DateTimeImmutable` — is rejected
  rather than silently stamping the message with the time it was read
  ([#18](https://github.com/rasuvaeff/yii3-outbox/issues/18)).

### Added

- A property over the `Processor` batch lifecycle: for any batch, attempt
  counts, backoff states and publisher behaviour (success, `PublishException`,
  unexpected exception), no message is left in `Processing` and none is left
  `Pending` with its attempts spent.
- A property over `Serializer::deserialize()`: any input either yields a message
  or fails with `InvalidArgumentException`.

### Changed

- Document `SerializerInterface` as the extension point it is, in both READMEs
  and `llms.txt`; document what `Processor` guarantees about claimed messages.
- Raise `rasuvaeff/property-testing-testo` to `^0.6`.

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

