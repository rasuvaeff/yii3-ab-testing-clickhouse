<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingClickHouse\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\ClickHouseToolkit\ClickHouseBatchWriter;
use Rasuvaeff\ClickHouseToolkit\ClickHouseClientFactory;
use Rasuvaeff\ClickHouseToolkit\ClickHouseConfig;
use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationRunner;
use Rasuvaeff\Yii3AbTesting\Assignment;
use Rasuvaeff\Yii3AbTesting\AssignmentContext;
use Rasuvaeff\Yii3AbTestingClickHouse\ClickHouseConversionTracker;
use Rasuvaeff\Yii3AbTestingClickHouse\ClickHouseExposureTracker;
use SimPod\ClickHouseClient\Client\ClickHouseClient;
use SimPod\ClickHouseClient\Format\JsonEachRow;

/**
 * End-to-end test against a real ClickHouse server. Skipped unless
 * CLICKHOUSE_HOST is set. Applies the shipped migrations, tracks events through
 * the buffered trackers, and reads them back.
 */
#[CoversNothing]
final class ClickHouseIntegrationTest extends TestCase
{
    private ClickHouseClient $client;

    private static function env(string $name, string $default): string
    {
        $value = getenv($name);

        return $value === false || $value === '' ? $default : $value;
    }

    #[\Override]
    protected function setUp(): void
    {
        $host = getenv('CLICKHOUSE_HOST');
        if ($host === false || $host === '') {
            $this->markTestSkipped('CLICKHOUSE_HOST is not set; skipping integration tests.');
        }

        $this->client = (new ClickHouseClientFactory(new ClickHouseConfig(
            host: $host,
            port: (int) self::env('CLICKHOUSE_PORT', '8123'),
            database: self::env('CLICKHOUSE_DB', 'default'),
            username: self::env('CLICKHOUSE_USER', 'default'),
            password: self::env('CLICKHOUSE_PASSWORD', ''),
        )))->create();

        foreach (['ab_exposures', 'ab_conversions', '_migrations'] as $table) {
            $this->client->executeQuery('DROP TABLE IF EXISTS ' . $table);
        }

        (new ClickHouseMigrationRunner($this->client, dirname(__DIR__, 2) . '/migrations'))->run();
    }

    #[Test]
    public function flushesExposuresToClickHouse(): void
    {
        $writer = new ClickHouseBatchWriter($this->client, 'ab_exposures', ClickHouseExposureTracker::COLUMNS);
        $tracker = new ClickHouseExposureTracker(writer: $writer);

        $tracker->trackExposure(new Assignment(
            experiment: 'checkout-button',
            variant: 'green',
            subjectId: 'user-1',
            context: AssignmentContext::forEnvironment('production'),
        ));
        $tracker->trackExposure(new Assignment(experiment: 'checkout-button', variant: 'control', subjectId: 'user-2'));
        $tracker->flush();

        $this->assertSame(2, $this->countRows('ab_exposures'));
    }

    #[Test]
    public function flushesConversionsToClickHouse(): void
    {
        $writer = new ClickHouseBatchWriter($this->client, 'ab_conversions', ClickHouseConversionTracker::COLUMNS);
        $tracker = new ClickHouseConversionTracker(writer: $writer);

        $tracker->trackConversion(
            new Assignment(experiment: 'checkout-button', variant: 'green', subjectId: 'user-1'),
            goal: 'purchase',
        );
        $tracker->flush();

        $this->assertSame(1, $this->countRows('ab_conversions'));
        $this->assertSame('purchase', $this->firstGoal());
    }

    private function countRows(string $table): int
    {
        $output = $this->client->select(sprintf('SELECT count() AS cnt FROM %s', $table), new JsonEachRow());

        return (int) ($output->data[0]['cnt'] ?? 0);
    }

    private function firstGoal(): string
    {
        $output = $this->client->select('SELECT goal FROM ab_conversions LIMIT 1', new JsonEachRow());

        return (string) ($output->data[0]['goal'] ?? '');
    }
}
