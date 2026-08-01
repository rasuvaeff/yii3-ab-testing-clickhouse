<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingClickHouse\Tests\Integration;

use Rasuvaeff\ClickHouseToolkit\ClickHouseBatchWriter;
use Rasuvaeff\ClickHouseToolkit\ClickHouseClientFactory;
use Rasuvaeff\ClickHouseToolkit\ClickHouseConfig;
use Rasuvaeff\ClickHouseToolkit\ClickHouseDataReader;
use Rasuvaeff\ClickHouseToolkit\ClickHouseQueryBuilder;
use Rasuvaeff\Yii3AbTestingClickHouse\AnalyticsSchemaV2;
use Rasuvaeff\Yii3AbTestingClickHouse\SchemaMigrations;
use SimPod\ClickHouseClient\Client\ClickHouseClient;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * End-to-end test against a real ClickHouse server. Skipped unless
 * CLICKHOUSE_HOST is set. This package no longer writes events itself, so the
 * only thing left to verify from here is the read side of the contract: apply
 * the shipped schema v2 migrations, insert core's own golden fixture rows
 * (the exact rows `EventSerializer` produces), and read them back unchanged.
 */
#[Test]
#[CoversNothing]
final class ClickHouseIntegrationTest
{
    private const string GOLDEN_FIXTURE = __DIR__ . '/../../vendor/rasuvaeff/yii3-ab-testing/fixtures/golden-event-v2.json';

    private ClickHouseClient $client;
    private bool $skip = true;

    private function env(string $name, string $default): string
    {
        $value = getenv($name);

        return $value === false || $value === '' ? $default : $value;
    }

    #[BeforeTest]
    public function setUp(): void
    {
        $host = getenv('CLICKHOUSE_HOST');
        if ($host === false || $host === '') {
            return;
        }

        $this->skip = false;
        $this->client = (new ClickHouseClientFactory(new ClickHouseConfig(
            host: $host,
            port: (int) $this->env('CLICKHOUSE_PORT', '8123'),
            database: $this->env('CLICKHOUSE_DB', 'default'),
            username: $this->env('CLICKHOUSE_USER', 'default'),
            password: $this->env('CLICKHOUSE_PASSWORD', ''),
        )))->create();

        foreach ([AnalyticsSchemaV2::EXPOSURES_TABLE, AnalyticsSchemaV2::CONVERSIONS_TABLE, '_migrations'] as $table) {
            $this->client->executeQuery('DROP TABLE IF EXISTS ' . $table);
        }

        (new SchemaMigrations($this->client))->apply();
    }

    public function exposureRowSurvivesTheSchemaRoundTrip(): void
    {
        if ($this->skip) {
            return;
        }

        $fixture = $this->goldenRow('exposure');

        (new ClickHouseBatchWriter($this->client, AnalyticsSchemaV2::EXPOSURES_TABLE, AnalyticsSchemaV2::EXPOSURE_COLUMNS))
            ->write([$fixture]);

        $stored = $this->readOne(AnalyticsSchemaV2::EXPOSURES_TABLE, AnalyticsSchemaV2::EXPOSURE_COLUMNS);

        foreach (AnalyticsSchemaV2::EXPOSURE_COLUMNS as $column) {
            Assert::same((string) $stored[$column], (string) $fixture[$column], sprintf('Column "%s" did not round-trip', $column));
        }
    }

    public function conversionRowSurvivesTheSchemaRoundTrip(): void
    {
        if ($this->skip) {
            return;
        }

        $fixture = $this->goldenRow('conversion');

        (new ClickHouseBatchWriter($this->client, AnalyticsSchemaV2::CONVERSIONS_TABLE, AnalyticsSchemaV2::CONVERSION_COLUMNS))
            ->write([$fixture]);

        $stored = $this->readOne(AnalyticsSchemaV2::CONVERSIONS_TABLE, AnalyticsSchemaV2::CONVERSION_COLUMNS);

        foreach (AnalyticsSchemaV2::CONVERSION_COLUMNS as $column) {
            Assert::same((string) $stored[$column], (string) $fixture[$column], sprintf('Column "%s" did not round-trip', $column));
        }
    }

    /**
     * @return array<string, string>
     */
    private function goldenRow(string $kind): array
    {
        /** @var array<string, array{row: array<string, string>}> $fixture */
        $fixture = (array) json_decode((string) file_get_contents(self::GOLDEN_FIXTURE), associative: true, flags: \JSON_THROW_ON_ERROR);
        $row = $fixture[$kind]['row'];
        unset($row['v']);

        return $row;
    }

    /**
     * @param non-empty-list<string> $columns
     * @return array<string, mixed>
     */
    private function readOne(string $table, array $columns): array
    {
        $reader = new ClickHouseDataReader(
            client: $this->client,
            table: $table,
            queryBuilder: ClickHouseQueryBuilder::create(allowedFields: $columns),
            mapper: static fn(array $row): array => $row,
            columns: $columns,
        );

        /** @var array<string, mixed>|null $row */
        $row = $reader->readOne();
        Assert::notNull($row);

        return $row;
    }
}
