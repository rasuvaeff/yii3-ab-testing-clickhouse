# AGENTS.md — yii3-ab-testing-clickhouse

Guidance for AI agents working on this package. Read before changing code.

## What this is

**2.0 changed what this package is.** It used to be a tracker adapter that
wrote events; it is now the owner of the analytics schema and, from 2.1, the
reporting layer. If you are about to add a writer here, read `UPGRADE.md`
first — the direct write path was removed on purpose, and re-adding it in any
form (including "but with `async_insert`") reintroduces a synchronous insert in
the visitor's latency.



This package owns the ClickHouse analytics schema (`ab_exposures_v2`,
`ab_conversions_v2`) and reads from it. It does **not** write events: they
arrive through `yii3-ab-testing-outbox` plus a worker, or through a
log-shipping collector reading core's `Logger*` sinks.
Namespace: `Rasuvaeff\Yii3AbTestingClickHouse`.

Public API: `AnalyticsSchemaV2` (table names and ordered insert columns, pinned
to the shipped DDL by `SchemaContractTest`) and `SchemaMigrations` (applies the
shipped `.sql`). Reporting lands in 2.1.

DI: `config/di.php` binds **nothing**. In 1.x it bound `ExposureTracker` and
`ConversionTracker`, which made installing this package next to the outbox
adapter a `yiisoft/config` `Duplicate key` error; that conflict is gone with the
writers.

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green
   `composer build`. "Should work" does not count.
2. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.
3. **`AnalyticsSchemaV2` and the DDL are one contract.** Producers check their
   route columns against the constants, so the two drifting apart means a
   producer writing confidently into a table that does not match. Column ORDER
   is part of it: an INSERT lists columns positionally, so a reorder writes a
   variant into the subject column with no error. `SchemaContractTest` pins
   them; never make it optional.
4. **Never add a writer back.** The direct path was removed because under
   PHP-FPM it issued a synchronous insert of a few rows per request, before the
   response was emitted. `async_insert` moves the batching server-side but keeps
   the round-trip in the visitor's latency and leaves ~15 lines of package code.
   See `UPGRADE.md`.

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
- `retention/` is opt-in deployment SQL, never part of automatic migrations.
  Do not enable deletion on package upgrade. Schema v2 remains disabled; the
  internal secondary sink is only a dual-write readiness boundary.
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
- Code: `declare(strict_types=1)`, `final readonly class`, `#[\Override]`,
  explicit types. Nothing here holds mutable state any more.
- **Read with `FINAL`.** ReplacingMergeTree collapses on merge, not on insert,
  and delivery is at-least-once, so a plain count over-counts after any retry.
  `AnalyticsSchemaV2::DEDUPLICATION_NOTE` says so in code; R3's queries must
  honour it.
- **`ingested_at` is never supplied by a producer** — the table fills it with
  `DEFAULT now()`. It records arrival, while partitioning and deduplication both
  depend on occurrence (`occurred_at`, carried from the event).
- **v1 tables are not migrated into v2 and never will be.** Their rows have no
  event identity and their `ts` is ingestion time, so a backfill would produce
  rows that look comparable to v2 and are not. They stay readable on their own.

## When you finish

- Update `README.md` and `README.ru.md` together (and `examples/` if usage
  changed); update `CHANGELOG.md` when releasing.
- Re-run `composer build` and paste the output.
