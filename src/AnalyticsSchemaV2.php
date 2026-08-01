<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingClickHouse;

/**
 * The canonical analytics schema, as a contract other packages can check
 * against.
 *
 * This package owns the schema and no longer writes to it: events arrive either
 * through the durable outbox exporter or through a log-shipping collector. The
 * producer therefore needs something to agree with, and a shipped `.sql` file
 * is not checkable from PHP — hence these constants, pinned to the DDL by
 * {@see \Rasuvaeff\Yii3AbTestingClickHouse\Tests\SchemaContractTest}.
 *
 * The order matters: an INSERT lists columns positionally, so a producer that
 * reorders them writes a variant into the subject column without any error.
 *
 * `ingested_at` is absent on purpose — the table fills it with `DEFAULT now()`,
 * and it is the one field a producer must not supply, since it records arrival
 * rather than occurrence.
 *
 * @api
 */
final class AnalyticsSchemaV2
{
    public const string EXPOSURES_TABLE = 'ab_exposures_v2';

    public const string CONVERSIONS_TABLE = 'ab_conversions_v2';

    /**
     * Insert columns for an exposure, in order.
     *
     * @var non-empty-list<string>
     */
    public const array EXPOSURE_COLUMNS = [
        'event_id',
        'occurred_at',
        'experiment',
        'variant',
        'subject_id',
        'decision_reason',
        'assignment_source',
        'experiment_revision',
        'environment',
        'dimensions',
    ];

    /**
     * Insert columns for a conversion, in order.
     *
     * @var non-empty-list<string>
     */
    public const array CONVERSION_COLUMNS = [
        'event_id',
        'occurred_at',
        'experiment',
        'variant',
        'subject_id',
        'goal',
        'decision_reason',
        'assignment_source',
        'experiment_revision',
        'environment',
        'dimensions',
        'exposure_event_id',
    ];

    /**
     * Deduplication in ClickHouse is deferred until a merge, so a query that
     * reads the table without collapsing duplicates will over-count after any
     * retried delivery. Every report must use `FINAL` or group by `event_id`.
     */
    public const string DEDUPLICATION_NOTE = 'ReplacingMergeTree collapses on merge only: read with FINAL or GROUP BY event_id';
}
