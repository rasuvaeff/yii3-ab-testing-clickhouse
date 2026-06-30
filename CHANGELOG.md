# Changelog

## 1.0.2 — 2026-06-30

- Add `/benchmarks` and `/Makefile` to `.gitattributes` export-ignore.

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

