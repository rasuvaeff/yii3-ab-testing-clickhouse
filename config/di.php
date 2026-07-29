<?php

declare(strict_types=1);

use Psr\Log\LoggerInterface;
use Rasuvaeff\ClickHouseToolkit\ClickHouseBatchWriter;
use Rasuvaeff\ClickHouseToolkit\ClickHouseClientFactory;
use Rasuvaeff\Yii3AbTesting\ConversionTracker;
use Rasuvaeff\Yii3AbTesting\ExposureTracker;
use Rasuvaeff\Yii3AbTestingClickHouse\ClickHouseConversionTracker;
use Rasuvaeff\Yii3AbTestingClickHouse\ClickHouseExposureTracker;
use Rasuvaeff\Yii3AbTestingClickHouse\ClickHouseTrackingFlushMiddleware;

/** @var array $params */

return [
    ClickHouseTrackingFlushMiddleware::class => static function (
        ExposureTracker $exposureTracker,
        ConversionTracker $conversionTracker,
        LoggerInterface $logger,
    ): ClickHouseTrackingFlushMiddleware {
        return new ClickHouseTrackingFlushMiddleware(
            exposureTracker: $exposureTracker,
            conversionTracker: $conversionTracker,
            logger: $logger,
        );
    },
    ExposureTracker::class => static function (
        ClickHouseClientFactory $clickHouseClientFactory,
        LoggerInterface $logger,
    ) use ($params): ExposureTracker {
        $config = $params['rasuvaeff/yii3-ab-testing-clickhouse'] ?? [];

        return new ClickHouseExposureTracker(
            writer: new ClickHouseBatchWriter(
                client: $clickHouseClientFactory->create(),
                table: $config['exposuresTable'] ?? 'ab_exposures',
                columns: ClickHouseExposureTracker::COLUMNS,
                batchSize: $config['batchSize'] ?? 1000,
            ),
            autoFlushSize: $config['autoFlushSize'] ?? 1000,
            logger: $logger,
        );
    },
    ConversionTracker::class => static function (
        ClickHouseClientFactory $clickHouseClientFactory,
        LoggerInterface $logger,
    ) use ($params): ConversionTracker {
        $config = $params['rasuvaeff/yii3-ab-testing-clickhouse'] ?? [];

        return new ClickHouseConversionTracker(
            writer: new ClickHouseBatchWriter(
                client: $clickHouseClientFactory->create(),
                table: $config['conversionsTable'] ?? 'ab_conversions',
                columns: ClickHouseConversionTracker::COLUMNS,
                batchSize: $config['batchSize'] ?? 1000,
            ),
            autoFlushSize: $config['autoFlushSize'] ?? 1000,
            logger: $logger,
        );
    },
];
