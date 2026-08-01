-- Conversion half of canonical schema v2. Same columns as the exposure table
-- plus the goal and the link back to the exposure, so a report can group both
-- without joining against the experiment definition.
CREATE TABLE IF NOT EXISTS {{conversions_table_v2}}
(
    event_id            String,
    occurred_at         DateTime64(3, 'UTC'),
    ingested_at         DateTime('UTC') DEFAULT now(),
    experiment          LowCardinality(String),
    variant             LowCardinality(String),
    subject_id          String,
    goal                LowCardinality(String),
    decision_reason     LowCardinality(String),
    assignment_source   LowCardinality(String),
    experiment_revision LowCardinality(String) DEFAULT '',
    environment         LowCardinality(String) DEFAULT '',
    dimensions          String DEFAULT '{}',
    -- Empty when the conversion carried no receipt; attribution then falls back
    -- to the configured window around the subject's first exposure.
    exposure_event_id   String DEFAULT ''
)
ENGINE = ReplacingMergeTree(ingested_at)
PARTITION BY toYYYYMM(occurred_at)
ORDER BY (experiment, event_id)
