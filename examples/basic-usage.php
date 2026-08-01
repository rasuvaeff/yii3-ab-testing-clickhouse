<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Rasuvaeff\Yii3AbTestingClickHouse\AnalyticsSchemaV2;
use Rasuvaeff\Yii3AbTestingClickHouse\SchemaMigrations;

/**
 * This package owns the analytics schema and reads from it. It does not write:
 * events arrive through the durable outbox exporter or a log-shipping
 * collector, never from the request path.
 *
 * Nothing here needs a server — it prints the contract a producer must match
 * and the query shape a reader must use.
 */
echo "Tables\n";
echo '  exposures:   ' . AnalyticsSchemaV2::EXPOSURES_TABLE . "\n";
echo '  conversions: ' . AnalyticsSchemaV2::CONVERSIONS_TABLE . "\n";

echo "\nInsert columns, in order — a producer that reorders them writes a\n";
echo "variant into the subject column without any error:\n";

foreach (AnalyticsSchemaV2::EXPOSURE_COLUMNS as $i => $column) {
    echo sprintf("  %2d. %s\n", $i + 1, $column);
}

echo "\nA conversion carries the same fields plus:\n";

foreach (array_diff(AnalyticsSchemaV2::CONVERSION_COLUMNS, AnalyticsSchemaV2::EXPOSURE_COLUMNS) as $column) {
    echo '  - ' . $column . "\n";
}

echo "\nNote what is absent: ingested_at. The table fills it with DEFAULT now(),\n";
echo "and it is the one column a producer must not supply — it records arrival,\n";
echo "not occurrence, and occurrence is what partitioning and dedup depend on.\n";

echo "\nApplying the schema (needs a ClickHouse client):\n";
echo "  (new SchemaMigrations(\$client))->apply();\n";
echo '  DDL lives in ' . SchemaMigrations::path() . "\n";

echo "\nReading — " . AnalyticsSchemaV2::DEDUPLICATION_NOTE . ":\n";
echo <<<SQL
    SELECT variant, uniqExact(subject_id) AS subjects
    FROM ab_exposures_v2 FINAL
    WHERE experiment = 'checkout_button'
      AND decision_reason = 'assigned'
    GROUP BY variant

SQL;
echo "\nWithout FINAL a retried delivery is counted twice, and retries are\n";
echo "expected: delivery is at-least-once by design.\n";
