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
use Rasuvaeff\Yii3AbTestingClickHouse\ClickHouseTrackingFlushMiddleware;
use Rasuvaeff\Yii3AbTestingClickHouse\Tests\Support\FakePsrFactory;
use Rasuvaeff\Yii3AbTestingClickHouse\Tests\Support\TrackingRequestHandler;
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

        $this->clientFactory = new ClickHouseClientFactory(new ClickHouseConfig(
            host: $host,
            port: (int) $this->env('CLICKHOUSE_PORT', '8123'),
            database: $this->env('CLICKHOUSE_DB', 'default'),
            username: $this->env('CLICKHOUSE_USER', 'default'),
            password: $this->env('CLICKHOUSE_PASSWORD', ''),
        ));

        $client = $this->clientFactory->create();
        foreach (['ab_exposures', 'ab_conversions', '_migrations'] as $table) {
            $client->executeQuery('DROP TABLE IF EXISTS ' . $table);
        }

        // the shipped DDL is parameterised: without these the runner refuses to
        // run rather than sending "{{exposures_table}}" to the server
        (new ClickHouseMigrationRunner(
            client: $client,
            migrationsPath: dirname(__DIR__, 2) . '/migrations',
            placeholders: [
                'exposures_table' => 'ab_exposures',
                'conversions_table' => 'ab_conversions',
            ],
        ))->run();
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

    public function outerMiddlewareFlushesEventsTrackedByDownstreamHandler(): void
    {
        if (!isset($this->clientFactory)) {
            return;
        }

        $exposureTracker = new ClickHouseExposureTracker(writer: new ClickHouseBatchWriter(
            client: $this->clientFactory->create(),
            table: 'ab_exposures',
            columns: ClickHouseExposureTracker::COLUMNS,
        ));
        $conversionTracker = new ClickHouseConversionTracker(writer: new ClickHouseBatchWriter(
            client: $this->clientFactory->create(),
            table: 'ab_conversions',
            columns: ClickHouseConversionTracker::COLUMNS,
        ));
        $response = FakePsrFactory::response();
        $handler = new TrackingRequestHandler(
            exposureTracker: $exposureTracker,
            conversionTracker: $conversionTracker,
            assignment: new Assignment(experiment: 'middleware-order', variant: 'control', subjectId: 'user-3'),
            response: $response,
        );
        $middleware = new ClickHouseTrackingFlushMiddleware(
            exposureTracker: $exposureTracker,
            conversionTracker: $conversionTracker,
        );

        $actual = $middleware->process(FakePsrFactory::serverRequest(), $handler);

        Assert::same($actual, $response);
        Assert::same($this->countRows('ab_exposures'), 1);
        Assert::same($this->countRows('ab_conversions'), 1);
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
