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
`config/di.php` is covered by `ConfigWiringTest`, not by cs/psalm/testo.

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green
   `composer build`. "Should work" does not count.
2. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.
3. **Tracking failures never break the request.** Trackers append to an in-memory
   buffer; writes happen on `flush()` (middleware / shutdown) or amortized via
   auto-flush at `autoFlushSize` multiples. A threshold event can therefore make
   a network call, but a failed auto-flush or middleware flush must never throw
   into the request. Keep events on failed writes, and log both delivery failures
   and any events dropped at the buffer cap.
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

`make test-coverage` and `make mutation` bootstrap `pcov` inside the
`composer:2` container because the base image has no coverage driver.
`composer.lock` is gitignored (library).

## Invariants & gotchas

- **The shipped DDL is parameterised, and the two sides are configured
  separately.** `migrations/*.sql` carry `{{exposures_table}}` /
  `{{conversions_table}}`; the runner resolves them (`clickhouse-toolkit` ^1.6)
  before hashing. This package's own `exposuresTable`/`conversionsTable` params
  reach only the writers — the placeholders come from
  `'rasuvaeff/yii3-clickhouse-toolkit' => ['migrationPlaceholders' => …]`,
  because `yiisoft/config` lets exactly one vendor package define a params key.
  Both READMEs show defining the name once and referencing it twice.
- **Never hard-code a table name back into the DDL.** `MigrationPlaceholderTest`
  fails if you do: nothing in PHP references those tokens, so a rename would
  otherwise surface only at deploy time as "unresolved placeholder".
- The runner hashes the **resolved** SQL, so switching these files to
  placeholders was invisible to existing installations (both `sha1` values are
  unchanged for the default names). The same property means renaming after an
  apply is a divergence, not a silent second table.
- Trackers depend on the toolkit's `ClickHouseWriterInterface` (injected), so unit
  tests use in-memory writers and spies — no server needed for `composer build`.
- `flush()` writes the buffer then clears it; an empty buffer writes nothing; a
  failed explicit tracker write keeps the buffer (caller may retry).
- Tracker auto-flush errors and cap-driven event loss are separate PSR-3 warning
  signals. Keep their stable `event` values (`flush_failed` / `dropped`),
  `trackerKind`, counts, and the original exception in structured context; DI
  must pass the application logger to both trackers.
- Boolean flags are written as `UInt8` (`0`/`1`); `environment` defaults to `''`
  when no `AssignmentContext` is present. `ts` is not written — the table fills it
  with `DEFAULT now()`.
- `ClickHouseTrackingFlushMiddleware` must wrap the handler in `try/finally` and
  swallow/log tracker flush errors, otherwise analytics can break user traffic.
- Integration test (`tests/Integration/ClickHouseIntegrationTest`) is skipped
  locally unless `CLICKHOUSE_HOST` is set; CI always supplies a live ClickHouse
  service. It applies `migrations/` via `ClickHouseMigrationRunner`. The app must
  register a `ClickHouseClientFactory` and `LoggerInterface` in DI.
- **Any change to `migrations/` must be verified with the Integration suite
  actually running**, not just `composer build`. Before the live CI job existed,
  1.1.0 shipped with this test broken by the placeholder change and 1.1.1 fixed
  it:

  ```bash
  docker run -d --name ch-abtest -p 8124:8123 -e CLICKHOUSE_PASSWORD=ch_test clickhouse/clickhouse-server:24.8
  docker run --rm --network host -v "$PWD/..":/repo -w /repo/yii3-ab-testing-clickhouse \
    -e CLICKHOUSE_HOST=127.0.0.1 -e CLICKHOUSE_PORT=8124 -e CLICKHOUSE_PASSWORD=ch_test \
    composer:2 sh -lc 'vendor/bin/testo --suite=Integration'
  ```
- Code: `declare(strict_types=1)`, `final class` (trackers hold a mutable buffer so
  they are not `readonly`), `#[\Override]`, explicit types.

## When you finish

- Update `README.md` and `README.ru.md` together (and `examples/` if usage
  changed); update `CHANGELOG.md` when releasing.
- Re-run `composer build` and paste the output.
