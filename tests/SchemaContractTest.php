<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingClickHouse\Tests;

use Rasuvaeff\Yii3AbTestingClickHouse\AnalyticsSchemaV2;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * Pins the PHP constants to the shipped DDL. Producers check their route
 * columns against the constants, so the two drifting apart would mean a
 * producer writing confidently into a table that does not match — the exact
 * failure schema v2 exists to end.
 */
#[Test]
#[Covers(AnalyticsSchemaV2::class)]
final class SchemaContractTest
{
    private const string MIGRATIONS = __DIR__ . '/../migrations/';

    /**
     * @param non-empty-list<string> $columns
     */
    #[DataProvider('tableProvider')]
    public function everyDeclaredColumnExistsInTheDdlInTheSameOrder(string $file, array $columns): void
    {
        $ddl = (string) file_get_contents(self::MIGRATIONS . $file);

        $position = -1;

        foreach ($columns as $column) {
            $found = strpos($ddl, "\n    " . $column . ' ');
            Assert::true($found !== false, sprintf('Column "%s" is missing from %s', $column, $file));
            Assert::true((int) $found > $position, sprintf('Column "%s" is out of order in %s', $column, $file));
            $position = (int) $found;
        }
    }

    public static function tableProvider(): iterable
    {
        yield 'exposures' => ['0003_create_ab_exposures_v2.sql', AnalyticsSchemaV2::EXPOSURE_COLUMNS];
        yield 'conversions' => ['0004_create_ab_conversions_v2.sql', AnalyticsSchemaV2::CONVERSION_COLUMNS];
    }

    /**
     * `ingested_at` records arrival, not occurrence. A producer that supplied
     * it would overwrite the server's own clock with its own.
     *
     * @param non-empty-list<string> $columns
     */
    #[DataProvider('tableProvider')]
    public function ingestedAtIsInTheTableButNotInTheInsertColumns(string $file, array $columns): void
    {
        $ddl = (string) file_get_contents(self::MIGRATIONS . $file);

        Assert::string($ddl)->contains('ingested_at');
        Assert::false(\in_array('ingested_at', $columns, true));
    }

    /**
     * Deduplication only works if a retried delivery lands in the same
     * partition, and the partition key is derived from the event time.
     */
    #[DataProvider('tableProvider')]
    public function deduplicationIsPossibleAtAll(string $file, array $columns): void
    {
        $ddl = (string) file_get_contents(self::MIGRATIONS . $file);

        Assert::string($ddl)->contains('ReplacingMergeTree');
        Assert::string($ddl)->contains('PARTITION BY toYYYYMM(occurred_at)');
        Assert::string($ddl)->contains('ORDER BY (experiment, event_id)');
        Assert::true(\in_array('event_id', $columns, true));
    }

    public function conversionCarriesEverythingAnExposureDoes(): void
    {
        $extra = array_values(array_diff(
            AnalyticsSchemaV2::CONVERSION_COLUMNS,
            AnalyticsSchemaV2::EXPOSURE_COLUMNS,
        ));

        Assert::same(array_diff(AnalyticsSchemaV2::EXPOSURE_COLUMNS, AnalyticsSchemaV2::CONVERSION_COLUMNS), []);
        Assert::same($extra, ['goal', 'exposure_event_id']);
    }

    /**
     * The names are part of the deployment contract: a rename after an apply
     * silently creates a second, empty table rather than migrating anything.
     */
    public function tableNamesAreTheOnesTheDdlPlaceholdersResolveTo(): void
    {
        Assert::same(AnalyticsSchemaV2::EXPOSURES_TABLE, 'ab_exposures_v2');
        Assert::same(AnalyticsSchemaV2::CONVERSIONS_TABLE, 'ab_conversions_v2');
        Assert::string((string) file_get_contents(self::MIGRATIONS . '0003_create_ab_exposures_v2.sql'))
            ->contains('{{exposures_table_v2}}');
        Assert::string((string) file_get_contents(self::MIGRATIONS . '0004_create_ab_conversions_v2.sql'))
            ->contains('{{conversions_table_v2}}');
    }
}
