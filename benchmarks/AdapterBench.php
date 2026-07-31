<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingClickHouse\Benchmarks;

use Rasuvaeff\ClickHouseToolkit\ClickHouseWriterInterface;
use Rasuvaeff\Yii3AbTesting\Assignment;
use Rasuvaeff\Yii3AbTestingClickHouse\ClickHouseExposureTracker;
use Testo\Bench;

final class AdapterBench
{
    #[Bench(
        callables: [
            'threshold-flush-1000' => [self::class, 'thresholdFlush'],
            'large-buffer-10000' => [self::class, 'largeBufferFlush'],
        ],
        calls: 100,
        iterations: 10,
    )]
    public static function append(): void
    {
        $tracker = new ClickHouseExposureTracker(writer: self::writer(), autoFlushSize: 10_000);
        $tracker->trackExposure(self::assignment());
    }

    public static function thresholdFlush(): void
    {
        $tracker = new ClickHouseExposureTracker(writer: self::writer(), autoFlushSize: 1_000);

        for ($i = 0; $i < 1_000; ++$i) {
            $tracker->trackExposure(self::assignment());
        }
    }

    public static function largeBufferFlush(): void
    {
        $tracker = new ClickHouseExposureTracker(writer: self::writer(), autoFlushSize: 20_000);

        for ($i = 0; $i < 10_000; ++$i) {
            $tracker->trackExposure(self::assignment());
        }

        $tracker->flush();
    }

    private static function assignment(): Assignment
    {
        return new Assignment(experiment: 'checkout-button', variant: 'treatment', subjectId: 'user-42');
    }

    private static function writer(): ClickHouseWriterInterface
    {
        return new class implements ClickHouseWriterInterface {
            #[\Override]
            public function write(\Traversable|array $rows): void
            {
                foreach ($rows as $_) {
                }
            }
        };
    }
}
