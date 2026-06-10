<?php

declare(strict_types=1);

use Rasuvaeff\ClickHouseToolkit\ClickHouseBatchWriter;
use Rasuvaeff\Yii3AbTesting\ConversionTracker;
use Rasuvaeff\Yii3AbTesting\ExposureTracker;
use Rasuvaeff\Yii3AbTestingClickHouse\ClickHouseConversionTracker;
use Rasuvaeff\Yii3AbTestingClickHouse\ClickHouseExposureTracker;
use SimPod\ClickHouseClient\Client\ClickHouseClient;

/** @var array $params */

return [
    ExposureTracker::class => static function (ClickHouseClient $client) use ($params): ExposureTracker {
        $config = $params['rasuvaeff/yii3-ab-testing-clickhouse'] ?? [];

        return new ClickHouseExposureTracker(
            writer: new ClickHouseBatchWriter(
                client: $client,
                table: $config['exposuresTable'] ?? 'ab_exposures',
                columns: ClickHouseExposureTracker::COLUMNS,
                batchSize: $config['batchSize'] ?? 1000,
            ),
        );
    },
    ConversionTracker::class => static function (ClickHouseClient $client) use ($params): ConversionTracker {
        $config = $params['rasuvaeff/yii3-ab-testing-clickhouse'] ?? [];

        return new ClickHouseConversionTracker(
            writer: new ClickHouseBatchWriter(
                client: $client,
                table: $config['conversionsTable'] ?? 'ab_conversions',
                columns: ClickHouseConversionTracker::COLUMNS,
                batchSize: $config['batchSize'] ?? 1000,
            ),
        );
    },
];
