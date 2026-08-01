# Changelog

## Unreleased

### Removed

- **Breaking.** The direct writers are gone: `ClickHouseExposureTracker`,
  `ClickHouseConversionTracker`, `ClickHouseTrackingFlushMiddleware`,
  `ClickHouseWriterSink`, `CompositeTrackingBatchSink`,
  `TrackingBatchSinkInterface`, `TrackingObserverInterface` and
  `NullTrackingObserver`. Under PHP-FPM the flush middleware issued a
  synchronous 1–3 row INSERT per request, before the response was emitted, and
  the buffer never filled because the worker did not outlive the request.
  Events now arrive through the durable outbox exporter or a log-shipping
  collector.
- **Breaking.** `config/di.php` binds nothing. It used to bind `ExposureTracker`
  and `ConversionTracker`, which made installing this package alongside the
  outbox adapter a `yiisoft/config` `Duplicate key` error.

### Added

- Canonical analytics schema v2 (`ab_exposures_v2`, `ab_conversions_v2`) with
  `event_id`, `occurred_at`, `decision_reason`, `assignment_source`,
  `experiment_revision` and `dimensions`, on
  `ReplacingMergeTree` ordered by `(experiment, event_id)`.
- `AnalyticsSchemaV2` — the column contract producers check against, pinned to
  the shipped DDL by a test.
- `SchemaMigrations` — applies the shipped `.sql` without every consumer
  hardcoding a `vendor/` path and its own placeholder map.

### Changed

- **Breaking.** Requires `rasuvaeff/yii3-ab-testing` `^2.0`.
- The v1 tables are untouched and still created: they hold history that is not
  migrated into v2, because their rows have no event identity and their `ts` is
  ingestion time rather than event time.

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.2.0 — 2026-08-01

- Add `TrackingObserverInterface` signals for buffered, written, failed flush,
  and dropped events, with a null default wired through config-plugin.
- Add opt-in schema-v1 retention/TTL templates with explicit enable and disable
  operations; upgrades do not activate data deletion.
- Replace constructor-only benchmarks with append, threshold-flush, and
  large-buffer workloads.
- Route writes through a fan-out-capable internal batch sink and expose an
  opt-in secondary sink without shipping or enabling schema v2.

## 1.1.2 — 2026-07-29

- Failed tracker auto-flushes now emit a PSR-3 warning with the tracker kind,
  buffered count, and write exception. Buffer-cap event loss emits a separate
  warning with the dropped count. The config-plugin passes the application's
  `LoggerInterface` to the middleware and both trackers.
- Correct the delivery contract: reaching `autoFlushSize` may perform a network
  call during `trackExposure()` / `trackConversion()`, and direct ClickHouse
  delivery is best effort. Document the required outer middleware position.
- Run the Integration suite against a live ClickHouse service in CI so schema,
  migration, and write/read regressions can no longer pass via a skipped suite.

## 1.1.1 — 2026-07-25

- Fix `tests/Integration/ClickHouseIntegrationTest`: it built the migration
  runner without placeholders, so against a real ClickHouse it aborted with
  "unresolved placeholder" as of 1.1.0. The suite is skipped unless
  `CLICKHOUSE_HOST` is set, so CI stayed green while the path was broken —
  verified both ways against a live server before and after the fix.

## 1.1.0 — 2026-07-25

- The shipped DDL no longer hard-codes table names: `migrations/*.sql` use
  `{{exposures_table}}` / `{{conversions_table}}`, resolved by
  `ClickHouseMigrationRunner` (requires `rasuvaeff/clickhouse-toolkit` `^1.6`,
  or `rasuvaeff/yii3-clickhouse-toolkit` `^1.1` when wired through params).
  Until now `exposuresTable` / `conversionsTable` repointed only the **writer**
  while the migration always created `ab_exposures` / `ab_conversions` — setting
  them produced a writer inserting into a table nothing had created.
- **Existing installations are unaffected.** The runner hashes the resolved SQL,
  and resolving the new files with the default names reproduces the old files
  byte for byte (verified: both `sha1` values are unchanged), so no migration is
  reported as diverged.
- Renaming a table *after* the migration has been applied is reported as a
  divergence — the README says what to do about it.

## 1.0.1 — 2026-06-27

- Migrate test suite from PHPUnit to Testo. Internal change, no public API impact.

## 1.0.0 — 2026-06-12

- Added `ClickHouseTrackingFlushMiddleware`: a PSR-15 request-end flush that wraps
  the downstream handler in `try/finally`, flushes both trackers, and logs/swallows
  flush failures so analytics never breaks the response flow.
- `config/di.php` now binds `ClickHouseTrackingFlushMiddleware` alongside
  `ExposureTracker` and `ConversionTracker`.
- Trackers implement `FlushableTracker` from core ^1.2, so composites and apps can flush through the tracker interfaces.
- Auto-flush: the buffer is written automatically once it reaches `autoFlushSize` (default 1000, configurable via params). A failed auto-flush never throws into the request; events are kept and retried, capped at ten thresholds.
- `is_sticky` column in both tables and in `COLUMNS`; sticky assignments are distinguishable in analytics.

- `ClickHouseExposureTracker` — buffers exposures and writes them to ClickHouse on `flush()`.
- `ClickHouseConversionTracker` — buffers conversions (with `goal`) and writes them on `flush()`.
- Built on `rasuvaeff/clickhouse-toolkit` `ClickHouseBatchWriter`; tracking never blocks the request.
- `migrations/` — ClickHouse DDL for `ab_exposures` and `ab_conversions` (MergeTree, monthly partitions), applied by the toolkit's `ClickHouseMigrationRunner`.
- Yii3 config-plugin: binds `ExposureTracker`, `ConversionTracker`, and `ClickHouseTrackingFlushMiddleware` from `config/di.php`; table names and batch size in `config/params.php`.
