<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingClickHouse\Tests;

use Rasuvaeff\Yii3AbTestingClickHouse\AnalyticsSchemaV2;
use Rasuvaeff\Yii3AbTestingClickHouse\SchemaMigrations;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * Covers what a consumer gets wrong without this class: the path to the shipped
 * DDL and the exact placeholder tokens. Both fail at deploy time otherwise —
 * as a missing directory or an "unresolved placeholder" — never at build time.
 */
#[Test]
#[Covers(SchemaMigrations::class)]
final class SchemaMigrationsTest
{
    public function pathPointsAtTheShippedMigrations(): void
    {
        $path = SchemaMigrations::path();

        Assert::true(is_dir($path));

        $files = glob($path . '/*.sql');
        Assert::notNull($files);
        Assert::same(\count((array) $files), 4);
    }

    /**
     * Every token the DDL uses must be in the map. A missing one surfaces only
     * when the runner refuses the unresolved placeholder, at deploy time.
     */
    public function everyPlaceholderTheDdlUsesIsProvided(): void
    {
        $placeholders = SchemaMigrations::placeholders();
        $sql = '';

        foreach ((array) glob(SchemaMigrations::path() . '/*.sql') as $file) {
            $sql .= (string) file_get_contents((string) $file);
        }

        preg_match_all('/\{\{(\w+)}}/', $sql, $matches);
        $used = array_unique($matches[1]);

        Assert::same(array_values(array_diff($used, array_keys($placeholders))), []);
    }

    public function defaultsResolveToTheSchemaContract(): void
    {
        $placeholders = SchemaMigrations::placeholders();

        Assert::same($placeholders['exposures_table_v2'], AnalyticsSchemaV2::EXPOSURES_TABLE);
        Assert::same($placeholders['conversions_table_v2'], AnalyticsSchemaV2::CONVERSIONS_TABLE);
    }

    /**
     * The v1 tables keep their names: an installation upgrading from 1.x still
     * reads its history, and the two generations must never collide.
     */
    public function legacyTableNamesAreUnchanged(): void
    {
        $placeholders = SchemaMigrations::placeholders();

        Assert::same($placeholders['exposures_table'], 'ab_exposures');
        Assert::same($placeholders['conversions_table'], 'ab_conversions');
    }

    public function customNamesReplaceOnlyTheV2Tables(): void
    {
        $placeholders = SchemaMigrations::placeholders('exp_custom', 'conv_custom');

        Assert::same($placeholders['exposures_table_v2'], 'exp_custom');
        Assert::same($placeholders['conversions_table_v2'], 'conv_custom');
        Assert::same($placeholders['exposures_table'], 'ab_exposures');
    }
}
