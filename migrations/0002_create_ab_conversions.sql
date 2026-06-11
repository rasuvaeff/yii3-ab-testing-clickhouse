CREATE TABLE IF NOT EXISTS ab_conversions
(
    experiment  String,
    variant     String,
    subject_id  String,
    goal        String,
    is_forced   UInt8 DEFAULT 0,
    is_fallback UInt8 DEFAULT 0,
    is_sticky   UInt8 DEFAULT 0,
    environment String DEFAULT '',
    ts          DateTime DEFAULT now()
)
ENGINE = MergeTree
PARTITION BY toYYYYMM(ts)
ORDER BY (experiment, variant, goal, ts)
