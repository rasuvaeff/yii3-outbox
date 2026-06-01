# AGENTS.md — yii3-outbox

Guidance for AI agents working on this package. Read before changing code.

## What this is

`rasuvaeff/yii3-outbox` implements the transactional outbox pattern for Yii3.
It provides a stateless core for storing messages in an outbox and publishing
them reliably with retry policies. Namespace: `Rasuvaeff\Yii3Outbox`.

Public API:
- `OutboxMessage` — immutable message value object
- `OutboxStatus` — enum: `Pending`, `Published`, `Failed`
- `SerializerInterface` / `Serializer` — JSON serialization
- `StorageInterface` — storage contract (save, findPending, markPublished, markFailed)
- `PublisherInterface` — publishing contract
- `PublishException` — thrown on publish failure
- `RetryPolicy` — configurable max attempts and delay
- `Processor` — fetches pending messages and publishes them
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
```

Or with Make:

```bash
make build
make cs:fix
make psalm
make test
```

`composer.lock` is gitignored (library).

## Invariants & gotchas

- `OutboxMessage` is `final readonly` — use `withStatus()` and `withAttempt()` for modifications.
- `Processor::process()` increments attempts before publishing.
- `RetryPolicy::isReadyForRetry()` checks both attempt count and delay elapsed.
- `InMemoryStorage` does not persist between requests — test use only.
- Code: `declare(strict_types=1)`, `final readonly class`, `#[\Override]`,
  explicit types.

## When you finish

- Update `README.md` (and `examples/` if usage changed); update `CHANGELOG.md`
  when releasing.
- Re-run `composer build` and paste the output.
