<?php

declare(strict_types=1);

use Rasuvaeff\Yii3AbTestingClickHouse\AnalyticsSchemaV2;

return [
    'rasuvaeff/yii3-ab-testing-clickhouse' => [
        // Schema v2 table names, for reporting queries. The DDL placeholders
        // are defined separately under the toolkit's `migrationPlaceholders`
        // key, because yiisoft/config lets exactly one vendor package define a
        // params key — both READMEs show defining the name once and
        // referencing it twice.
        'exposuresTable' => AnalyticsSchemaV2::EXPOSURES_TABLE,
        'conversionsTable' => AnalyticsSchemaV2::CONVERSIONS_TABLE,
        // v1 tables, kept for reading history. They are NOT migrated into v2:
        // their rows have no event identity and their `ts` is ingestion time,
        // so a backfill would produce rows that look comparable and are not.
        'legacyExposuresTable' => 'ab_exposures',
        'legacyConversionsTable' => 'ab_conversions',
    ],
];
