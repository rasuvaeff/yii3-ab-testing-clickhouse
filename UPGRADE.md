# Upgrade guide

## 1.x → 2.0

This package no longer writes to ClickHouse. It owns the analytics schema and,
from 2.1, the reporting queries. Events now arrive through the durable outbox
exporter or a log-shipping collector.

The steps are manual because public API was removed — there is no shim that
would keep the old wiring working, and a silent one would be worse than an
error.

### Why the writer went away

`ClickHouseTrackingFlushMiddleware` flushed in a `finally` around the request
handler. Under PHP-FPM that means:

- a synchronous `INSERT` of one to three rows **per request** — ClickHouse's
  documented anti-pattern, since every insert creates at least one part;
- the insert happens **before the response is emitted**, because Yii3's SAPI
  emitter runs after the middleware stack and never calls
  `fastcgi_finish_request()`, so the network call sits in the visitor's latency;
- the `autoFlushSize = 1000` buffer never fills, because an FPM worker does not
  outlive the request. The buffering, auto-flush, drop cap and observer signals
  only ever engaged under RoadRunner, Swoole or the CLI.

Setting `async_insert=1, wait_for_async_insert=0` moves the batching to the
server and makes it tolerable, but then the package's own code is fifteen lines
over `clickhouse-toolkit` — not enough to justify a public contract.

### 1. Choose a delivery path

| Path | Install | Trade-off |
|---|---|---|
| Durable | `rasuvaeff/yii3-ab-testing-outbox` + a worker | survives an analytics outage; needs a table and a worker |
| Log shipping | core's `LoggerExposureTracker` + Vector/Fluent Bit | no worker, no request-time network call; delivery is the collector's job |

### 2. Remove the old wiring

Delete every binding of the removed classes and the middleware from your
application config:

```diff
-use Rasuvaeff\Yii3AbTestingClickHouse\ClickHouseExposureTracker;
-use Rasuvaeff\Yii3AbTestingClickHouse\ClickHouseConversionTracker;
-use Rasuvaeff\Yii3AbTestingClickHouse\ClickHouseTrackingFlushMiddleware;
```

Removed: `ClickHouseExposureTracker`, `ClickHouseConversionTracker`,
`ClickHouseTrackingFlushMiddleware`, `ClickHouseWriterSink`,
`CompositeTrackingBatchSink`, `TrackingBatchSinkInterface`,
`TrackingObserverInterface`, `NullTrackingObserver`.

`config/di.php` now binds nothing, so installing this package alongside the
outbox adapter is no longer a `yiisoft/config` `Duplicate key` error.

### 3. Create the v2 tables

```php
use Rasuvaeff\Yii3AbTestingClickHouse\SchemaMigrations;

(new SchemaMigrations($client, $logger))->apply();
```

`ab_exposures_v2` and `ab_conversions_v2` are **new** tables. The v1 tables are
untouched and still created by the same runner.

### 4. Leave the v1 data where it is

There is deliberately no backfill. A v1 row has no event identity, so a
migration would have to synthesise one, and its `ts` column is ingestion time
rather than event time. The result would look comparable to v2 and would not be
— which is worse than two separate tables.

Query the old tables directly for history, and do not union them into a v2
report.

### 5. Read with `FINAL`

Deduplication in `ReplacingMergeTree` happens on merge, not on insert, so a
query that does not collapse duplicates over-counts after any retried delivery
— and delivery is at-least-once by design:

```sql
SELECT variant, uniqExact(subject_id) AS subjects
FROM ab_exposures_v2 FINAL
WHERE experiment = 'checkout_button'
  AND decision_reason = 'assigned'
GROUP BY variant
```

`decision_reason = 'assigned'` is not optional either: forced (QA) traffic and
both fallback kinds must stay out of the analysis population.
