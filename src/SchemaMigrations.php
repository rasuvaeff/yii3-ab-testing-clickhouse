<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingClickHouse;

use Psr\Log\LoggerInterface;
use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationRunner;
use SimPod\ClickHouseClient\Client\ClickHouseClient;

/**
 * Applies the schema this package owns.
 *
 * Without it every consumer hardcodes
 * `vendor/rasuvaeff/yii3-ab-testing-clickhouse/migrations` and its own
 * placeholder map — a path that breaks on a vendor-dir rename and a map that
 * drifts from the DDL. The table names come from {@see AnalyticsSchemaV2}, so
 * the constants a producer checks against and the tables actually created can
 * only ever be the same.
 *
 * Applying migrations is deliberately explicit rather than automatic: creating
 * analytics tables is a deployment step, and the shipped `retention/` templates
 * are never applied here at all, because enabling row deletion on a package
 * upgrade would be indefensible.
 *
 * @api
 */
final readonly class SchemaMigrations
{
    public function __construct(
        private ClickHouseClient $client,
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * @param non-empty-string $exposuresTable
     * @param non-empty-string $conversionsTable
     *
     * @return list<string> Names of the migrations this call applied; already
     *     applied ones are skipped, so a second call returns an empty list.
     */
    public function apply(
        string $exposuresTable = AnalyticsSchemaV2::EXPOSURES_TABLE,
        string $conversionsTable = AnalyticsSchemaV2::CONVERSIONS_TABLE,
    ): array {
        return $this->runner($exposuresTable, $conversionsTable)->run();
    }

    /**
     * @param non-empty-string $exposuresTable
     * @param non-empty-string $conversionsTable
     */
    public function runner(
        string $exposuresTable = AnalyticsSchemaV2::EXPOSURES_TABLE,
        string $conversionsTable = AnalyticsSchemaV2::CONVERSIONS_TABLE,
    ): ClickHouseMigrationRunner {
        return new ClickHouseMigrationRunner(
            client: $this->client,
            migrationsPath: self::path(),
            logger: $this->logger,
            placeholders: [
                // v1 tables are still created by 0001/0002: an installation
                // upgrading from 1.x keeps reading its history, and the two
                // generations never share a table name.
                'exposures_table' => 'ab_exposures',
                'conversions_table' => 'ab_conversions',
                'exposures_table_v2' => $exposuresTable,
                'conversions_table_v2' => $conversionsTable,
            ],
        );
    }

    /**
     * Directory of the shipped `.sql` files, resolved from this file rather
     * than assumed relative to the project root.
     */
    public static function path(): string
    {
        return \dirname(__DIR__) . '/migrations';
    }
}
