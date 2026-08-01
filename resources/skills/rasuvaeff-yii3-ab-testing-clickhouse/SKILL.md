---
name: rasuvaeff-yii3-ab-testing-clickhouse
description: >-
  ClickHouse analytics schema for Yii3 A/B testing with
  rasuvaeff/yii3-ab-testing-clickhouse — AnalyticsSchemaV2, SchemaMigrations,
  the v2 tables and how to query them. Use when writing, reviewing or debugging
  analytics tables, migrations, retention, or reporting queries in a project
  that has this package installed.
---

# rasuvaeff/yii3-ab-testing-clickhouse

Owns the analytics schema and reads from it. Namespace
`Rasuvaeff\Yii3AbTestingClickHouse\`.

## Safety rules — verify these on every change

1. **This package does NOT write events, and must not start.** The direct
   writers were removed in 2.0: under PHP-FPM the flush middleware issued a
   synchronous 1–3 row INSERT per request, before the response was emitted,
   and the buffer never filled because the worker did not outlive the request.
   `async_insert` moves the batching server-side but keeps the round-trip in
   the visitor's latency. Events arrive through `yii3-ab-testing-outbox` plus a
   worker, or a log-shipping collector reading core's `Logger*` sinks.

2. **Column ORDER is part of the contract.** An INSERT lists columns
   positionally, so a producer that reorders them writes a variant into the
   subject column with no error. `AnalyticsSchemaV2` is pinned to the shipped
   DDL by a test; never let them drift.

3. **Read with `FINAL`** (or `GROUP BY event_id` + `argMax`).
   `ReplacingMergeTree` collapses on merge, not on insert, and delivery is
   at-least-once — so a plain count over-counts after any retry.

4. **`ingested_at` is never supplied by a producer.** The table fills it with
   `DEFAULT now()`. It records arrival; partitioning and deduplication both
   depend on occurrence (`occurred_at`, carried from the event).

5. **The analysis population is `decision_reason = 'assigned'`.** Forced (QA)
   traffic and both fallback kinds must stay out, or the numbers describe
   something other than the experiment.

6. **`retention/` is opt-in deployment SQL, never applied automatically.**
   Enabling row deletion on a package upgrade would be indefensible.

## v1 data is not migrated, and will not be

The v1 tables stay readable on their own. Their rows have no event identity and
their `ts` is ingestion time rather than event time, so a backfill would produce
rows that look comparable to v2 and are not — worse than two separate tables.

## Canonical usage

```php
// Applying is explicit: creating analytics tables is a deployment step.
(new SchemaMigrations($client, $logger))->apply();

// An application running its own migration pipeline still needs the exact
// tokens — guessing them yields "unresolved placeholder" at deploy time.
SchemaMigrations::placeholders();
```

```sql
SELECT variant, uniqExact(subject_id) AS subjects
FROM ab_exposures_v2 FINAL
WHERE experiment = 'checkout_button' AND decision_reason = 'assigned'
GROUP BY variant
```

`dimensions` is a JSON string, not a `Map` — the outbox exporter rejects nested
payload fields, and both delivery paths must produce identical rows. Read it
with `JSONExtract(dimensions, 'Map(String, String)')`.

## Full API

`vendor/rasuvaeff/yii3-ab-testing-clickhouse/llms.txt`. Upgrading:
`vendor/rasuvaeff/yii3-ab-testing-clickhouse/UPGRADE.md`.
