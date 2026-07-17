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
- `rasuvaeff/clickhouse-toolkit` ^1.1
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
from the container and uses it to build the batch writers. Bind the factory in
your application:

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
files, applied by the toolkit's `ClickHouseMigrationRunner`:

```php
use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationRunner;

(new ClickHouseMigrationRunner(
    $clickHouseClient,
    __DIR__ . '/vendor/rasuvaeff/yii3-ab-testing-clickhouse/migrations',
))->run();
```

| Table | Columns |
|---|---|
| `ab_exposures` | `experiment, variant, subject_id, is_forced, is_fallback, is_sticky, environment, ts` |
| `ab_conversions` | `experiment, variant, subject_id, goal, is_forced, is_fallback, is_sticky, environment, ts` |

Both are `MergeTree` partitioned by `toYYYYMM(ts)`; `ts` defaults to `now()`.

## Usage

```php
use Rasuvaeff\ClickHouseToolkit\ClickHouseBatchWriter;
use Rasuvaeff\Yii3AbTesting\AbTesting;
use Rasuvaeff\Yii3AbTestingClickHouse\ClickHouseConversionTracker;
use Rasuvaeff\Yii3AbTestingClickHouse\ClickHouseExposureTracker;

$exposure = new ClickHouseExposureTracker(
    writer: new ClickHouseBatchWriter($client, 'ab_exposures', ClickHouseExposureTracker::COLUMNS),
);
$conversion = new ClickHouseConversionTracker(
    writer: new ClickHouseBatchWriter($client, 'ab_conversions', ClickHouseConversionTracker::COLUMNS),
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

Tracking never makes a network call on `trackExposure()` or `trackConversion()`.
Rows are appended to an in-memory buffer and written on `flush()`. The package
ships `ClickHouseTrackingFlushMiddleware` for the recommended request-end flush:

```php
use Rasuvaeff\Yii3AbTestingClickHouse\ClickHouseTrackingFlushMiddleware;

return [
    ClickHouseTrackingFlushMiddleware::class,
    // place it late in the PSR-15 pipeline
];
```

The middleware wraps the downstream handler in `try/finally`, flushes both
trackers after the request, and swallows/logs flush failures so analytics never
breaks the user response.

If you do not use a PSR-15 pipeline, call `flush()` yourself once at request end
or from `register_shutdown_function()`.

## API reference

| Class | Description |
|---|---|
| `ClickHouseExposureTracker` | Buffers exposures; `flush()` batch-writes to `ab_exposures` |
| `ClickHouseConversionTracker` | Buffers conversions (with `goal`); `flush()` batch-writes to `ab_conversions` |
| `ClickHouseTrackingFlushMiddleware` | PSR-15 middleware that flushes both trackers safely at request end |

## Security

- Connection credentials travel via the toolkit's `ClickHouseClientFactory`
  (headers / config from env), never in URLs. The toolkit validates table and
  column identifiers and uses parameterized inserts.
- `subject_id` is stored verbatim and may be personally identifiable. Apply TTL /
  partition retention per your privacy policy.
- Middleware swallows flush failures by design, so add logging/monitoring for
  the warning message if analytics delivery matters operationally.

## Examples

See [examples/](examples/) for a runnable script (no server required — uses an
in-memory writer).

## Development

```bash
composer build          # full gate: validate + normalize + cs + psalm + test
composer cs:fix         # auto-fix code style
composer psalm          # static analysis
composer test           # run unit tests (integration tests skipped without CLICKHOUSE_HOST)
```

## License

BSD-3-Clause. See [LICENSE.md](LICENSE.md).
