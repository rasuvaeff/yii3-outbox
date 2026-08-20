# AGENTS.md — yii3-outbox

Guidance for AI agents working on this package. Read before changing code.

## What this is

`rasuvaeff/yii3-outbox` implements the transactional outbox pattern for Yii3.
It provides a stateless core for storing messages in an outbox and publishing
them reliably with retry policies. Namespace: `Rasuvaeff\Yii3Outbox`.

Public API:
- `Outbox` — facade: `record(type, payload, aggregateId?, id?)` → `OutboxMessage`
- `MessageIdGeneratorInterface` — produces the message id when none is passed
- `RandomHexIdGenerator` — default: 32 random hex characters
- `OutboxMessage` — immutable message value object with `aggregateId` support
- `OutboxStatus` — enum: `Pending`, `Processing`, `Published`, `Failed`
- `SerializerInterface` / `Serializer` — JSON serialization
- `StorageInterface` — storage contract (save, claim, findPending, markPublished,
  markFailed, getById)
- `PublisherInterface` — publishing contract
- `PublishException` — thrown on publish failure
- `RetryPolicy` — configurable max attempts and delay
- `Processor` — fetches pending messages and publishes them, returns `ProcessingResult`
- `ProcessingResult` — published/failed/skipped counters
- `InMemoryStorage` — test implementation of `StorageInterface`

DB storage is a separate package: `rasuvaeff/yii3-outbox-db`.

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green
   `composer build`. "Should work" does not count.
2. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.
3. **Storage is pluggable, and `save()` carries an unenforceable contract.**
   Never hardcode DB assumptions in core — use `StorageInterface` everywhere.
   That interface also states the invariant the whole pattern rests on:
   `Outbox::record()` must run inside the caller's business transaction, and the
   implementation must write through the caller's connection. The core opens no
   transaction and cannot enforce it, so it must stay documented in the
   `StorageInterface::save()` PHPDoc, both READMEs and `llms.txt`. Do not
   silently drop it.
4. **Preserve the public contract.** Update README + tests with any API change.

## Commands

No PHP/Composer on the host — run in Docker via the `composer:2` image.

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer psalm
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```

Or with Make:

```bash
make build
make cs-fix
make psalm
make test
make test-coverage
make mutation
make release-check
```

`composer.lock` is gitignored (library).
`make test-coverage` and `make mutation` bootstrap `pcov` inside the
`composer:2` container because the base image has no coverage driver.

## Invariants & gotchas

- `OutboxMessage` is `final readonly` — use `withStatus(OutboxStatus)` and
  `withAttempt(DateTimeImmutable)` for modifications (both return new instances).
- `Processor::process()` increments attempts before publishing via `withAttempt($now)`.
- **Retry flow**: if publish fails and `shouldRetry` returns true → `storage->save($message)`
  (keeps status `Pending`). Otherwise `markFailed`.
- **Nothing leaves `process()` in `Processing`, and nothing stays `Pending`
  without attempts left.** A message claimed with its attempts already spent is
  failed on sight instead of being saved back as `Pending` (it would circle
  claim → skip → save forever). A publisher throwing anything other than
  `PublishException` is rethrown — it is a bug, not a delivery failure — but
  only after the current message is persisted per the retry policy and the rest
  of the claimed batch is released. Both invariants are pinned by a property
  test in `ProcessorTest`; do not "simplify" either branch away.
- `RetryPolicy::isReadyForRetry()` takes `DateTimeImmutable $now` — caller provides
  the clock, not the policy.
- **`claim()` is what `Processor::process()` calls, not `findPending()`.** It
  must atomically move up to `$limit` `Pending` messages to `Processing` and
  return them, so concurrent workers never receive the same message. Every
  claimed message must end in `markPublished()`, `markFailed()` or
  `save($msg->withStatus(Pending))` — nothing may stay `Processing`. Docs that
  show `findPending()` as a worker's fetch teach non-atomic polling; keep the
  distinction explicit in README/`llms.txt`.
- `findPending(array $types = [], int $limit = 1000)` is the read-only
  counterpart: it must return `Pending` messages with any attempt count —
  `RetryPolicy` filters which are ready for retry — but it locks and marks
  nothing, so it is for dashboards and diagnostics, never a worker.
- `$types` restricts to those message types (empty = all) so several consumers
  (e.g. a generic `Processor` and a ClickHouse exporter) can share one outbox.
  Since `claim()` hands a message to exactly one caller, their type sets must not
  overlap, or an overlapping message reaches only the first claimer.
- `InMemoryStorage` does not persist between requests — test use only. It
  implements `Countable` and `IteratorAggregate` alongside `StorageInterface`.
- `Outbox` and `Processor` require `Psr\Clock\ClockInterface` injection.
- **Message id: domain id first, generator second.** `record(id: ...)` skips the
  generator entirely — that is the path that keeps republished domain events
  deduplicable downstream. `RandomHexIdGenerator` must stay the default:
  changing it silently rewrites every installation's id scheme.
- **No UUID library in `require` — deliberately.** `id` is `VARCHAR(255)` in
  `yii3-outbox-db`, so any format fits and the choice belongs to the
  application; the README shows the five-line generator for symfony/uid and
  ramsey/uuid. Unlike `yii3-audit-log` (whose column is `VARCHAR(32)`, where
  the hex-32 UUIDv7 trick is subtle enough to ship as a tested class), this
  package has no reason to pick a library for the user.
- Code: `declare(strict_types=1)`, `final readonly class`, `#[\Override]`,
  explicit types.

- `examples/` is part of the public contract: keep scripts runnable and update
  `examples/README.md` when example usage changes.

## When you finish

- Update `README.md` **and `README.ru.md`** (both languages, same commit; and
  `examples/` if usage changed); update `CHANGELOG.md` when releasing.
- Re-run `composer build`; if the change affects the public API or release
  process, also run `make release-check`. Paste the output.
