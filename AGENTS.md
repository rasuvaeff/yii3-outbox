# AGENTS.md — yii3-outbox

Guidance for AI agents working on this package. Read before changing code.

## What this is

`rasuvaeff/yii3-outbox` implements the transactional outbox pattern for Yii3.
It provides a stateless core for storing messages in an outbox and publishing
them reliably with retry policies. Namespace: `Rasuvaeff\Yii3Outbox`.

Public API:
- `Outbox` — facade: `record(type, payload, aggregateId?)` → `OutboxMessage`
- `OutboxMessage` — immutable message value object with `aggregateId` support
- `OutboxStatus` — enum: `Pending`, `Published`, `Failed`
- `SerializerInterface` / `Serializer` — JSON serialization
- `StorageInterface` — storage contract (save, findPending, markPublished, markFailed, getById)
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
3. **Storage is pluggable.** Never hardcode DB assumptions in core. Use
   `StorageInterface` everywhere.
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
  (keeps status `Pending`). Only calls `markFailed` when retries are exhausted.
- `RetryPolicy::isReadyForRetry()` takes `DateTimeImmutable $now` — caller provides
  the clock, not the policy.
- `findPending(array $types = [], int $limit = 1000)` must return `Pending`
  messages with any attempt count — `RetryPolicy` filters which are ready for
  retry. `$types` restricts to those message types (empty = all) so several
  consumers (e.g. a generic `Processor` and a ClickHouse exporter) can share one
  outbox without competing for each other's messages.
- `InMemoryStorage` does not persist between requests — test use only.
- `Outbox` and `Processor` require `Psr\Clock\ClockInterface` injection.
- Code: `declare(strict_types=1)`, `final readonly class`, `#[\Override]`,
  explicit types.

- `examples/` is part of the public contract: keep scripts runnable and update
  `examples/README.md` when example usage changes.

## When you finish

- Update `README.md` (and `examples/` if usage changed); update `CHANGELOG.md`
  when releasing.
- Re-run `composer build`; if the change affects the public API or release
  process, also run `make release-check`. Paste the output.
