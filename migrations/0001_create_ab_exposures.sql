CREATE TABLE IF NOT EXISTS ab_exposures
(
    experiment  String,
    variant     String,
    subject_id  String,
    is_forced   UInt8 DEFAULT 0,
    is_fallback UInt8 DEFAULT 0,
    environment String DEFAULT '',
    ts          DateTime DEFAULT now()
)
ENGINE = MergeTree
PARTITION BY toYYYYMM(ts)
ORDER BY (experiment, variant, ts)
