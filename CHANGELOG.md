# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.0.0 — unreleased

- `ClickHouseExposureTracker` — buffers exposures and writes them to ClickHouse on `flush()`.
- `ClickHouseConversionTracker` — buffers conversions (with `goal`) and writes them on `flush()`.
- Built on `rasuvaeff/clickhouse-toolkit` `ClickHouseBatchWriter`; tracking never blocks the request.
- `migrations/` — ClickHouse DDL for `ab_exposures` and `ab_conversions` (MergeTree, monthly partitions), applied by the toolkit's `ClickHouseMigrationRunner`.
- Yii3 config-plugin: binds `ExposureTracker` and `ConversionTracker` from `config/di.php`; table names and batch size in `config/params.php`.
