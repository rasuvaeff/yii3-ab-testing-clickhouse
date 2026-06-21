<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingClickHouse\Benchmarks;

use Rasuvaeff\Yii3AbTestingClickHouse\ClickHouseConversionTracker;
use Rasuvaeff\Yii3AbTestingClickHouse\ClickHouseExposureTracker;
use Testo\Bench;

final class AdapterBench
{
    #[Bench(
        callables: [
            'autoflush-100' => [self::class, 'constructWithAutoFlush100'],
        ],
        calls: 1_000,
        iterations: 10,
    )]
    public static function constructWithAutoFlush1000(): ClickHouseConversionTracker
    {
        $writer = new class implements \Rasuvaeff\ClickHouseToolkit\ClickHouseWriterInterface {
            public function write(array $rows): void {}
        };

        return new ClickHouseConversionTracker(writer: $writer, autoFlushSize: 1_000);
    }

    public static function constructWithAutoFlush100(): ClickHouseConversionTracker
    {
        $writer = new class implements \Rasuvaeff\ClickHouseToolkit\ClickHouseWriterInterface {
            public function write(array $rows): void {}
        };

        return new ClickHouseConversionTracker(writer: $writer, autoFlushSize: 100);
    }
}
