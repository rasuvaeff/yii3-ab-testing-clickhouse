-- Canonical analytics schema v2. Both delivery paths — the durable outbox
-- exporter and log shipping — write logically identical rows here.
--
-- Deliberately a NEW table rather than an ALTER of `ab_exposures`: the v1
-- table has no event identity and its `ts` is ingestion time, not event time,
-- so backfilling would produce rows that look comparable to v2 and are not.
CREATE TABLE IF NOT EXISTS {{exposures_table_v2}}
(
    -- Minted once, by the core facade, and never regenerated downstream: this
    -- is the deduplication key of the whole pipeline, so a delivery retried
    -- after an uncertain outcome carries the same value.
    event_id            String,
    -- Event time, not delivery time. Partitioning depends on it, so a retry
    -- crossing a month boundary must land in the same partition or
    -- ReplacingMergeTree will not collapse the duplicate.
    occurred_at         DateTime64(3, 'UTC'),
    ingested_at         DateTime('UTC') DEFAULT now(),
    experiment          LowCardinality(String),
    variant             LowCardinality(String),
    subject_id          String,
    -- Two orthogonal axes: why this variant, and where the value came from.
    -- Plain String rather than Enum so a backfill can write values outside the
    -- PHP enum (`fallback_unspecified`) without an ALTER.
    decision_reason     LowCardinality(String),
    assignment_source   LowCardinality(String),
    experiment_revision LowCardinality(String) DEFAULT '',
    environment         LowCardinality(String) DEFAULT '',
    -- JSON object, not a Map: the outbox exporter rejects nested payload
    -- fields, and both delivery paths must produce byte-identical rows.
    dimensions          String DEFAULT '{}'
)
ENGINE = ReplacingMergeTree(ingested_at)
PARTITION BY toYYYYMM(occurred_at)
ORDER BY (experiment, event_id)
