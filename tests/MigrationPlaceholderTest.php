<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingClickHouse\Tests;

use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Test;

/**
 * The shipped DDL carries `{{…}}` tokens that the application resolves through
 * `rasuvaeff/yii3-clickhouse-toolkit`'s `migrationPlaceholders` param. Nothing
 * in PHP references those token names, so a rename here would only surface at
 * deploy time as "unresolved placeholder" — after the migration runner already
 * refused to run. These tests are the contract between the SQL files and the
 * README.
 */
#[Test]
#[CoversNothing]
final class MigrationPlaceholderTest
{
    private const string MIGRATIONS_DIR = __DIR__ . '/../migrations';

    public function shippedDdlUsesExactlyTheDocumentedTokens(): void
    {
        Assert::same($this->tokensIn('0001_create_ab_exposures.sql'), ['exposures_table']);
        Assert::same($this->tokensIn('0002_create_ab_conversions.sql'), ['conversions_table']);
    }

    public function ddlHardCodesNoTableName(): void
    {
        // the whole point of the change: `CREATE TABLE ab_exposures` in the file
        // would ignore the configured name and recreate the split this fixed
        foreach (['0001_create_ab_exposures.sql', '0002_create_ab_conversions.sql'] as $file) {
            $sql = $this->read($file);

            Assert::false(str_contains($sql, 'EXISTS ab_exposures'));
            Assert::false(str_contains($sql, 'EXISTS ab_conversions'));
        }
    }

    public function resolvingWithTheDefaultsProducesRunnableDdl(): void
    {
        $sql = str_replace('{{exposures_table}}', 'ab_exposures', $this->read('0001_create_ab_exposures.sql'));

        Assert::string($sql)->contains('CREATE TABLE IF NOT EXISTS ab_exposures');
        Assert::same(preg_match('/\{\{/', $sql), 0);
    }

    /**
     * @return list<string>
     */
    private function tokensIn(string $file): array
    {
        preg_match_all('/\{\{([^}]+)}}/', $this->read($file), $matches);

        return array_values(array_unique($matches[1]));
    }

    private function read(string $file): string
    {
        $contents = file_get_contents(self::MIGRATIONS_DIR . '/' . $file);
        Assert::true($contents !== false);

        return (string) $contents;
    }
}
