# AGENTS.md — yii3-ab-testing-clickhouse

Guidance for AI agents working on this package. Read before changing code.

## What this is

ClickHouse exposure and conversion trackers for Yii3 A/B testing. Implements
`ExposureTracker` and `ConversionTracker` from `rasuvaeff/yii3-ab-testing` by
buffering events in memory and writing them to ClickHouse in batches on an
explicit `flush()`, built on `rasuvaeff/clickhouse-toolkit`
(`ClickHouseBatchWriter`). This is the production analytics sink.
Namespace: `Rasuvaeff\Yii3AbTestingClickHouse`.

Public API: `ClickHouseExposureTracker`, `ClickHouseConversionTracker`,
`ClickHouseTrackingFlushMiddleware`. The trackers implement core's
`FlushableTracker`, expose a `COLUMNS` constant, and have configurable
`autoFlushSize`. Schema ships as ClickHouse `*.sql` files under `migrations/`,
applied by the toolkit's `ClickHouseMigrationRunner`.

DI: `config/di.php` binds `ExposureTracker`, `ConversionTracker`, and the flush
middleware class. The tracker factories pull a
`Rasuvaeff\ClickHouseToolkit\ClickHouseClientFactory` from the container and build a `ClickHouseBatchWriter` per table. The core binds neither tracker key; one
source owns each (compose several sinks with the core `Composite*Tracker`).
`config/di.php` is covered by `ConfigWiringTest`, not by cs/psalm/phpunit.

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green
   `composer build`. "Should work" does not count.
2. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.
3. **Tracking never blocks or breaks the request.** Trackers append to an
   in-memory buffer; writes happen on `flush()` (middleware / shutdown) or
   amortized via auto-flush at `autoFlushSize` multiples. Never write per event,
   and a failed auto-flush or middleware flush must never throw into the
   request. Keep events on failed tracker flush; log and swallow middleware
   flush failures.
4. **Preserve the public contract.** A tracker's `COLUMNS` constant must match
   the columns of the `ClickHouseBatchWriter` it is given and the table DDL in
   `migrations/`. Update README + tests with any API change.

## Commands

No PHP/Composer on the host — run in Docker via the `composer:2` image.

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer psalm
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
```

Or with Make: `make build`, `make cs-fix`, `make psalm`, `make test`,
`make mutation`. `composer.lock` is gitignored (library).

## Invariants & gotchas

- Trackers depend on the toolkit's `ClickHouseWriterInterface` (injected), so unit
  tests use in-memory writers and spies — no server needed for `composer build`.
- `flush()` writes the buffer then clears it; an empty buffer writes nothing; a
  failed explicit tracker write keeps the buffer (caller may retry).
- Boolean flags are written as `UInt8` (`0`/`1`); `environment` defaults to `''`
  when no `AssignmentContext` is present. `ts` is not written — the table fills it
  with `DEFAULT now()`.
- `ClickHouseTrackingFlushMiddleware` must wrap the handler in `try/finally` and
  swallow/log tracker flush errors, otherwise analytics can break user traffic.
- Integration test (`tests/Integration/ClickHouseIntegrationTest`) is skipped
  unless `CLICKHOUSE_HOST` is set; it applies `migrations/` via
  `ClickHouseMigrationRunner`. The app must register a `ClickHouseClientFactory` in DI.
- Code: `declare(strict_types=1)`, `final class` (trackers hold a mutable buffer so
  they are not `readonly`), `#[\Override]`, explicit types.

## When you finish

- Update `README.md` (and `examples/` if usage changed); update `CHANGELOG.md`
  when releasing.
- Re-run `composer build` and paste the output.
