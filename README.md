# rasuvaeff/yii3-ab-testing-clickhouse

[![Stable Version](https://img.shields.io/packagist/v/rasuvaeff/yii3-ab-testing-clickhouse.svg?label=stable)](https://packagist.org/packages/rasuvaeff/yii3-ab-testing-clickhouse)
[![Total Downloads](https://img.shields.io/packagist/dt/rasuvaeff/yii3-ab-testing-clickhouse.svg)](https://packagist.org/packages/rasuvaeff/yii3-ab-testing-clickhouse)
[![Build](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-ab-testing-clickhouse/build.yml?branch=master)](https://github.com/rasuvaeff/yii3-ab-testing-clickhouse/actions)
[![Static Analysis](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-ab-testing-clickhouse/static-analysis.yml?branch=master&label=static%20analysis)](https://github.com/rasuvaeff/yii3-ab-testing-clickhouse/actions)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/yii3-ab-testing-clickhouse/php)](https://packagist.org/packages/rasuvaeff/yii3-ab-testing-clickhouse)
[![License](https://img.shields.io/packagist/l/rasuvaeff/yii3-ab-testing-clickhouse.svg)](LICENSE.md)
[Русская версия](README.ru.md)

ClickHouse exposure and conversion trackers for Yii3 A/B testing. Implements the
`ExposureTracker` and `ConversionTracker` interfaces from `rasuvaeff/yii3-ab-testing`,
buffering events in memory and writing them to ClickHouse in batches.

> Using an AI coding assistant? [llms.txt](llms.txt) contains a compact API reference you can ingest in your prompt context.

## Requirements

- PHP 8.3+
- `rasuvaeff/yii3-ab-testing` ^1.2
- `rasuvaeff/clickhouse-toolkit` ^1.6
- a PSR-18 HTTP client (for example `guzzlehttp/guzzle`) for the ClickHouse connection

## Installation

```bash
composer require rasuvaeff/yii3-ab-testing-clickhouse
```

With Yii3 config-plugin this package binds `ExposureTracker`, `ConversionTracker`
and `ClickHouseTrackingFlushMiddleware` automatically. Do not bind the tracker
interfaces from another adapter at the same time or `yiisoft/config` reports a
`Duplicate key` error. To send events to several sinks, compose them with the
core `CompositeExposureTracker` / `CompositeConversionTracker`.

The DI factory pulls a `Rasuvaeff\ClickHouseToolkit\ClickHouseClientFactory`
and `Psr\Log\LoggerInterface` from the container. It uses the factory to build
the batch writers and sends delivery warnings to the logger. Bind both in your
application (Yii applications normally already provide the logger):

```php
use Rasuvaeff\ClickHouseToolkit\ClickHouseClientFactory;
use Rasuvaeff\ClickHouseToolkit\ClickHouseConfig;

return [
    ClickHouseClientFactory::class => static fn (): ClickHouseClientFactory => new ClickHouseClientFactory(
        new ClickHouseConfig(host: getenv('CLICKHOUSE_HOST') ?: '127.0.0.1', /* ... */),
    ),
];
```

## Database schema

DDL for the two event tables ships under `migrations/` as ClickHouse `*.sql`
files, applied by the toolkit's `ClickHouseMigrationRunner`. The table names are
`{{exposures_table}}` / `{{conversions_table}}` placeholders, resolved by the
runner before the file is hashed and executed:

```php
use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationRunner;

(new ClickHouseMigrationRunner(
    client: $clickHouseClient,
    migrationsPath: __DIR__ . '/vendor/rasuvaeff/yii3-ab-testing-clickhouse/migrations',
    placeholders: [
        'exposures_table' => 'ab_exposures',
        'conversions_table' => 'ab_conversions',
    ],
))->run();
```

Wired through `rasuvaeff/yii3-clickhouse-toolkit` (v1.1+), the same values come
from params. The name is used twice — by the writer and by the migration — so
define it once:

```php
// config/common/params.php
$exposures = 'ab_exposures';
$conversions = 'ab_conversions';

return [
    'rasuvaeff/yii3-ab-testing-clickhouse' => [
        'exposuresTable' => $exposures,
        'conversionsTable' => $conversions,
    ],
    'rasuvaeff/yii3-clickhouse-toolkit' => [
        'migrationPlaceholders' => [
            'exposures_table' => $exposures,
            'conversions_table' => $conversions,
        ],
    ],
];
```

Two params blocks rather than one because `yiisoft/config` allows exactly one
vendor package to define a given params key: this package cannot contribute to
the toolkit's `migrationPlaceholders` without a `Duplicate key` error.

Before v1.1 the params renamed only the **writer** while the shipped DDL always
created `ab_exposures` / `ab_conversions` — configuring them produced a writer
inserting into a table nothing had created.

**Renaming after the first apply.** The runner's checksum covers the resolved
SQL, so changing a name once the migration has been applied is reported as a
divergence instead of silently creating a second table. Create the new table
yourself (the DDL is in `migrations/`), or drop the file's row from the
`_migrations` table, then re-run.

| Table | Columns |
|---|---|
| `ab_exposures` | `experiment, variant, subject_id, is_forced, is_fallback, is_sticky, environment, ts` |
| `ab_conversions` | `experiment, variant, subject_id, goal, is_forced, is_fallback, is_sticky, environment, ts` |

Both are `MergeTree` partitioned by `toYYYYMM(ts)`; `ts` defaults to `now()`.

### Retention

Opt-in TTL templates for schema v1 live in `retention/`. They are deliberately
not migrations: installing or upgrading the package must never start deleting
analytics data. Resolve `{{exposures_table}}` / `{{conversions_table}}` and a
positive integer `{{retention_days}}`, review the SQL, then apply it through
your deployment process. The matching `disable_*_ttl.sql` templates remove the
policy without dropping existing rows. Changing the number of days is an
ordinary `MODIFY TTL` operation; ClickHouse applies it asynchronously.

## Usage

```php
use Rasuvaeff\ClickHouseToolkit\ClickHouseBatchWriter;
use Rasuvaeff\Yii3AbTesting\AbTesting;
use Rasuvaeff\Yii3AbTestingClickHouse\ClickHouseConversionTracker;
use Rasuvaeff\Yii3AbTestingClickHouse\ClickHouseExposureTracker;

$exposure = new ClickHouseExposureTracker(
    writer: new ClickHouseBatchWriter($client, 'ab_exposures', ClickHouseExposureTracker::COLUMNS),
    logger: $logger,
);
$conversion = new ClickHouseConversionTracker(
    writer: new ClickHouseBatchWriter($client, 'ab_conversions', ClickHouseConversionTracker::COLUMNS),
    logger: $logger,
);

$ab = new AbTesting(
    provider: $provider,
    strategy: $strategy,
    exposureTracker: $exposure,
    conversionTracker: $conversion,
);

$assignment = $ab->assign(experiment: 'checkout-button', subjectId: (string) $userId);
$ab->trackExposure($assignment);            // buffered, not sent yet
$ab->trackConversion($assignment, goal: 'purchase');
```

### Request-end flushing

Rows are appended to an in-memory buffer. Reaching `autoFlushSize` (1000 by
default) makes `trackExposure()` or `trackConversion()` attempt one batched
network write; otherwise writing happens on `flush()`. Direct ClickHouse
tracking is therefore a best-effort sink, not a durable queue. The package ships
`ClickHouseTrackingFlushMiddleware` for the recommended request-end flush:

```php
use Rasuvaeff\Yii3AbTestingClickHouse\ClickHouseTrackingFlushMiddleware;

return [
    // It must wrap every middleware/handler that can track an event.
    ClickHouseTrackingFlushMiddleware::class,
];
```

The middleware wraps the downstream handler in `try/finally`, flushes both
trackers after the request, and swallows/logs flush failures so analytics never
breaks the user response. Register it outside (before, in pipelines whose first
entry is outermost) all middleware and application code that can track events.

Failed auto-flushes keep their events for retry and emit
`Failed to auto-flush ClickHouse A/B testing tracker`. To bound worker memory,
the buffer is capped at `10 * autoFlushSize`; dropping the oldest events emits
`Dropped ClickHouse A/B testing events after repeated flush failures` with a
`droppedEvents` count. Their structured `event` values are `flush_failed` and
`dropped`. Monitor both warnings. Events still buffered when the process exits
are lost.

For metrics and traces, implement `TrackingObserverInterface` and bind it in DI.
It reports `buffered`, `written`, `flushFailed`, and `dropped` with the tracker
kind and event counts. Keep labels low-cardinality and never throw from an
observer. The default `NullTrackingObserver` has no overhead beyond the method
call. The PSR-3 warnings remain available independently.

The buffering code writes through an internal `TrackingBatchSinkInterface`.
Trackers accept an optional `secondarySink` for controlled dual-write rollout;
the package DI does not configure one and schema v2 is not shipped or enabled.
If a secondary write fails after the primary succeeded, the retained batch is
retried as a whole, so every target must tolerate at-least-once delivery.

If you do not use a PSR-15 pipeline, call `flush()` yourself once at request end
or from `register_shutdown_function()`.

## API reference

| Class | Description |
|---|---|
| `ClickHouseExposureTracker` | Buffers exposures; `flush()` batch-writes to `ab_exposures` |
| `ClickHouseConversionTracker` | Buffers conversions (with `goal`); `flush()` batch-writes to `ab_conversions` |
| `ClickHouseTrackingFlushMiddleware` | PSR-15 middleware that flushes both trackers safely at request end |
| `TrackingObserverInterface` | Lifecycle metrics/tracing signals for buffered, written, failed, and dropped events |
| `AnalyticsSchemaV2` | Column contract producers check against, pinned to the shipped DDL |
| `SchemaMigrations` | Applies the shipped `.sql` without hardcoding a vendor path |

## Security

- Connection credentials travel via the toolkit's `ClickHouseClientFactory`
  (headers / config from env), never in URLs. The toolkit validates table and
  column identifiers and uses parameterized inserts.
- `subject_id` is stored verbatim and may be personally identifiable. Apply TTL /
  partition retention per your privacy policy.
- Auto-flush and middleware flush failures are swallowed by design. Monitor the
  delivery and dropped-event warnings if analytics delivery matters
  operationally; use the outbox adapter when durable delivery is required.

## Examples

See [examples/](examples/) for a runnable script (no server required — uses an
in-memory writer).

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
