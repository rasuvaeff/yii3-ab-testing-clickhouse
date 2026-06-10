<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Rasuvaeff\ClickHouseToolkit\ClickHouseWriterInterface;
use Rasuvaeff\Yii3AbTesting\AbTesting;
use Rasuvaeff\Yii3AbTesting\ConfigExperimentProvider;
use Rasuvaeff\Yii3AbTesting\WeightedHashAssignmentStrategy;
use Rasuvaeff\Yii3AbTestingClickHouse\ClickHouseExposureTracker;

// A throwaway writer that prints rows instead of inserting them, so the example
// runs without a ClickHouse server. In production inject a
// Rasuvaeff\ClickHouseToolkit\ClickHouseBatchWriter built from your client.
$writer = new class implements ClickHouseWriterInterface {
    public function write(iterable $rows): void
    {
        foreach ($rows as $row) {
            echo json_encode($row, JSON_THROW_ON_ERROR) . "\n";
        }
    }
};

$exposure = new ClickHouseExposureTracker(writer: $writer);

$ab = new AbTesting(
    provider: new ConfigExperimentProvider(config: [
        'checkout-button' => [
            'salt' => 'checkout-v1',
            'fallbackVariant' => 'control',
            'variants' => ['control' => 50, 'green' => 50],
        ],
    ]),
    strategy: new WeightedHashAssignmentStrategy(),
    exposureTracker: $exposure,
);

foreach (['user-1', 'user-2', 'user-3'] as $userId) {
    $ab->trackExposure($ab->assign(experiment: 'checkout-button', subjectId: $userId));
}

// Once at request end:
$exposure->flush();
