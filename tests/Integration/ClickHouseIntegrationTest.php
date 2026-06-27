<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingClickHouse\Tests\Integration;

use Rasuvaeff\ClickHouseToolkit\ClickHouseBatchWriter;
use Rasuvaeff\ClickHouseToolkit\ClickHouseClientFactory;
use Rasuvaeff\ClickHouseToolkit\ClickHouseConfig;
use Rasuvaeff\ClickHouseToolkit\ClickHouseDataReader;
use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationRunner;
use Rasuvaeff\ClickHouseToolkit\ClickHouseQueryBuilder;
use Rasuvaeff\Yii3AbTesting\Assignment;
use Rasuvaeff\Yii3AbTesting\AssignmentContext;
use Rasuvaeff\Yii3AbTestingClickHouse\ClickHouseConversionTracker;
use Rasuvaeff\Yii3AbTestingClickHouse\ClickHouseExposureTracker;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * End-to-end test against a real ClickHouse server. Skipped unless
 * CLICKHOUSE_HOST is set. Applies the shipped migrations, tracks events through
 * the buffered trackers, and reads them back.
 */
#[Test]
#[CoversNothing]
final class ClickHouseIntegrationTest
{
    private ClickHouseClientFactory $clientFactory;

    private static function env(string $name, string $default): string
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

        $this->clientFactory = new ClickHouseClientFactory(new ClickHouseConfig(
            host: $host,
            port: (int) self::env('CLICKHOUSE_PORT', '8123'),
            database: self::env('CLICKHOUSE_DB', 'default'),
            username: self::env('CLICKHOUSE_USER', 'default'),
            password: self::env('CLICKHOUSE_PASSWORD', ''),
        ));

        $client = $this->clientFactory->create();
        foreach (['ab_exposures', 'ab_conversions', '_migrations'] as $table) {
            $client->executeQuery('DROP TABLE IF EXISTS ' . $table);
        }

        (new ClickHouseMigrationRunner($client, dirname(__DIR__, 2) . '/migrations'))->run();
    }

    public function flushesExposuresToClickHouse(): void
    {
        if (!isset($this->clientFactory)) {
            return;
        }

        $writer = new ClickHouseBatchWriter(
            $this->clientFactory->create(),
            'ab_exposures',
            ClickHouseExposureTracker::COLUMNS,
        );
        $tracker = new ClickHouseExposureTracker(writer: $writer);

        $tracker->trackExposure(new Assignment(
            experiment: 'checkout-button',
            variant: 'green',
            subjectId: 'user-1',
            context: AssignmentContext::forEnvironment('production'),
        ));
        $tracker->trackExposure(new Assignment(experiment: 'checkout-button', variant: 'control', subjectId: 'user-2'));
        $tracker->flush();

        Assert::same($this->countRows('ab_exposures'), 2);
    }

    public function flushesConversionsToClickHouse(): void
    {
        if (!isset($this->clientFactory)) {
            return;
        }

        $writer = new ClickHouseBatchWriter(
            $this->clientFactory->create(),
            'ab_conversions',
            ClickHouseConversionTracker::COLUMNS,
        );
        $tracker = new ClickHouseConversionTracker(writer: $writer);

        $tracker->trackConversion(
            new Assignment(experiment: 'checkout-button', variant: 'green', subjectId: 'user-1'),
            goal: 'purchase',
        );
        $tracker->flush();

        Assert::same($this->countRows('ab_conversions'), 1);
        Assert::same($this->firstGoal(), 'purchase');
    }

    private function countRows(string $table): int
    {
        return $this->reader(table: $table)->count();
    }

    private function firstGoal(): string
    {
        return (string) ($this->reader(table: 'ab_conversions', columns: ['goal'])->readOne()['goal'] ?? '');
    }

    /**
     * @param list<string> $columns
     */
    private function reader(string $table, array $columns = []): ClickHouseDataReader
    {
        return new ClickHouseDataReader(
            client: $this->clientFactory->create(),
            table: $table,
            queryBuilder: ClickHouseQueryBuilder::create(allowedFields: $columns),
            mapper: static fn(array $row): array => $row,
            columns: $columns,
        );
    }
}
