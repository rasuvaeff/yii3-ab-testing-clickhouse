# rasuvaeff/yii3-ab-testing-clickhouse

[![Stable Version](https://img.shields.io/packagist/v/rasuvaeff/yii3-ab-testing-clickhouse.svg?label=stable)](https://packagist.org/packages/rasuvaeff/yii3-ab-testing-clickhouse)
[![Total Downloads](https://img.shields.io/packagist/dt/rasuvaeff/yii3-ab-testing-clickhouse.svg)](https://packagist.org/packages/rasuvaeff/yii3-ab-testing-clickhouse)
[![Build](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-ab-testing-clickhouse/build.yml?branch=master)](https://github.com/rasuvaeff/yii3-ab-testing-clickhouse/actions)
[![Static Analysis](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-ab-testing-clickhouse/static-analysis.yml?branch=master&label=static%20analysis)](https://github.com/rasuvaeff/yii3-ab-testing-clickhouse/actions)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/yii3-ab-testing-clickhouse/php)](https://packagist.org/packages/rasuvaeff/yii3-ab-testing-clickhouse)
[![License](https://img.shields.io/packagist/l/rasuvaeff/yii3-ab-testing-clickhouse.svg)](LICENSE.md)
[Русская версия](README.ru.md)

The ClickHouse analytics schema for Yii3 A/B testing. It owns the schema v2
tables that both delivery paths write into, ships their migrations, and gives
producers a column contract to check against. It does **not** write events.

> Using an AI coding assistant? [llms.txt](llms.txt) contains a compact API reference you can ingest in your prompt context.
> Projects using the [llm/skills](https://github.com/roxblnfk/skills) Composer
> plugin also get this package's agent skill synced into `.agents/skills/`
> automatically on install.

> **Documentation site:** [rasuvaeff.github.io/yii3-ab-testing](https://rasuvaeff.github.io/yii3-ab-testing/)
> covers the whole family — including the
> [integration matrix](https://rasuvaeff.github.io/yii3-ab-testing/integration)
> for assembling a combination.

## Requirements

- PHP 8.3+
- `rasuvaeff/yii3-ab-testing` ^2.0
- `rasuvaeff/clickhouse-toolkit` ^1.6 and `simpod/clickhouse-client` ^0.8
  (with a PSR-18 HTTP client, for example `guzzlehttp/guzzle`) to apply the schema

## Installation

```bash
composer require rasuvaeff/yii3-ab-testing-clickhouse
```

Upgrading from 1.x? See [UPGRADE.md](UPGRADE.md) — the direct writers are gone.

## How events get here

This package has no tracker. Events arrive through one of two paths, and both
produce the same rows:

| Path | Install | Trade-off |
|---|---|---|
| Durable | `yii3-ab-testing-outbox` + `yii3-outbox-db` + a worker (`outbox:clickhouse:export`) | survives an analytics outage; needs a table and a running worker |
| Log shipping | core's `LoggerExposureTracker` / `LoggerConversionTracker` + Vector / Fluent Bit | no worker, no request-time network call; delivery is the collector's job |

Never insert from the request path. Under PHP-FPM that is a synchronous insert
of a few rows per request, inside the visitor's latency — the reason the direct
writer was removed in 2.0.

`config/di.php` binds nothing, so installing this package next to any tracker
adapter cannot cause a `yiisoft/config` `Duplicate key` error.

## Database schema

| Table | Constant | Rows |
|---|---|---|
| `ab_exposures_v2` | `AnalyticsSchemaV2::EXPOSURES_TABLE` | one per exposure event |
| `ab_conversions_v2` | `AnalyticsSchemaV2::CONVERSIONS_TABLE` | one per conversion, adds `goal` and `exposure_event_id` |

```sql
CREATE TABLE ab_exposures_v2
(
    event_id            String,
    occurred_at         DateTime64(3, 'UTC'),
    ingested_at         DateTime('UTC') DEFAULT now(),
    experiment          LowCardinality(String),
    variant             LowCardinality(String),
    subject_id          String,
    decision_reason     LowCardinality(String),
    assignment_source   LowCardinality(String),
    experiment_revision LowCardinality(String) DEFAULT '',
    environment         LowCardinality(String) DEFAULT '',
    dimensions          String DEFAULT '{}'
)
ENGINE = ReplacingMergeTree(ingested_at)
PARTITION BY toYYYYMM(occurred_at)
ORDER BY (experiment, event_id)
```

- `event_id` is minted once by the core and is the deduplication key of the
  whole pipeline: a delivery retried after an uncertain outcome carries the same
  value.
- `occurred_at` is event time, not delivery time. It drives the partition key,
  so a retry crossing a month boundary still lands in the same partition and
  collapses.
- `ingested_at` is filled by the table and must **not** be supplied by a
  producer.
- `dimensions` is a JSON **string**, not a `Map`: the outbox exporter rejects
  nested payload fields, and both delivery paths must produce identical rows.
  Read it with `JSONExtract(dimensions, 'Map(String, String)')`.

### Column contract

```php
use Rasuvaeff\Yii3AbTestingClickHouse\AnalyticsSchemaV2;

AnalyticsSchemaV2::EXPOSURE_COLUMNS;   // ordered insert columns
AnalyticsSchemaV2::CONVERSION_COLUMNS; // the same plus goal and exposure_event_id
```

Column **order** is part of the contract: an `INSERT` lists columns
positionally, so a producer that reorders them writes a variant into the subject
column without any error. `yii3-ab-testing-outbox` routes into these constants
rather than restating them, and a test pins them to the shipped DDL.

### Applying migrations

```php
use Rasuvaeff\Yii3AbTestingClickHouse\SchemaMigrations;

$applied = (new SchemaMigrations(client: $clickHouseClient, logger: $logger))->apply();
// list of applied migration names; a second call returns []
```

`apply()` runs the shipped `migrations/*.sql` through `clickhouse-toolkit`'s
`ClickHouseMigrationRunner`, resolved from the package itself rather than a
hardcoded `vendor/` path. Applying is explicit, never automatic: creating
analytics tables is a deployment step.

Custom table names go to `apply('exp_v2', 'conv_v2')`. An application that runs
its own migration pipeline — for example `rasuvaeff/yii3-clickhouse-toolkit` —
takes the directory and the exact token map from the package:

```php
use Rasuvaeff\Yii3AbTestingClickHouse\SchemaMigrations;

SchemaMigrations::path();          // directory of the shipped .sql files
SchemaMigrations::placeholders();  // ['exposures_table' => …, 'exposures_table_v2' => …, …]
```

**Renaming after the first apply.** The runner's checksum covers the resolved
SQL, so changing a name once a migration has been applied is reported as a
divergence instead of silently creating a second table.

### Schema v1 tables

The v1 tables `ab_exposures` / `ab_conversions` are still created, so an
installation upgraded from 1.x keeps reading its history. They are **not**
migrated into v2 and are not comparable row for row: v1 rows have no event
identity, and their `ts` is ingestion time.

### Retention

Opt-in TTL templates live in `retention/`. They are deliberately not
migrations: installing or upgrading the package must never start deleting
analytics data. The shipped templates target the **v1** tables (`{{exposures_table}}`
/ `{{conversions_table}}`, column `ts`); resolve them and a positive integer
`{{retention_days}}`, review the SQL, then apply it through your deployment
process. The matching `disable_*_ttl.sql` templates remove the policy without
dropping rows. For the v2 tables write the equivalent `MODIFY TTL` on
`occurred_at`.

## Reading results

```sql
SELECT variant, uniqExact(subject_id) AS subjects
FROM ab_exposures_v2 FINAL
WHERE experiment = 'checkout_button'
  AND decision_reason = 'assigned'
GROUP BY variant
```

- **`FINAL`** (or `GROUP BY event_id` with `argMax`). `ReplacingMergeTree`
  collapses duplicates on merge only, and delivery is at-least-once, so a plain
  count over-counts after any retry. `AnalyticsSchemaV2::DEDUPLICATION_NOTE`
  states the rule.
- **`decision_reason = 'assigned'`**. Forced (QA) traffic and both kinds of
  fallback are recorded but are not experiment participation.

## API reference

| Class | Description |
|---|---|
| `AnalyticsSchemaV2` | Table names and ordered insert columns of schema v2 — the contract producers check against, pinned to the shipped DDL |
| `SchemaMigrations` | Applies the shipped `.sql` files; exposes their path and placeholder map |

## Security

- Credentials travel through `clickhouse-toolkit`'s client configuration
  (headers / env), never in URLs.
- `subject_id` is stored verbatim and may be personally identifiable. Apply a
  retention policy that matches your privacy obligations.

## Examples

See [examples/](examples/) for a runnable script (no server required) that
prints the column contract and the query shape a reader must use.

## Development

```bash
composer build          # full gate: validate + normalize + cs + psalm + test
composer cs:fix         # auto-fix code style
composer psalm          # static analysis
composer test           # run unit tests (integration tests skipped without CLICKHOUSE_HOST)
vendor/bin/testo --suite=Integration # requires CLICKHOUSE_HOST; runs live in CI
```

## License

BSD-3-Clause. See [LICENSE.md](LICENSE.md).
