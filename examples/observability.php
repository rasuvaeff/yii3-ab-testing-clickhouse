<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Rasuvaeff\ClickHouseToolkit\ClickHouseWriterInterface;
use Rasuvaeff\Yii3AbTesting\Assignment;
use Rasuvaeff\Yii3AbTestingClickHouse\ClickHouseExposureTracker;
use Rasuvaeff\Yii3AbTestingClickHouse\TrackingObserverInterface;

$writer = new class implements ClickHouseWriterInterface {
    #[\Override]
    public function write(iterable $rows): void {}
};
$observer = new class implements TrackingObserverInterface {
    #[\Override]
    public function buffered(string $trackerKind, int $bufferedEvents): void
    {
        echo "$trackerKind buffered=$bufferedEvents\n";
    }

    #[\Override]
    public function written(string $trackerKind, int $writtenEvents): void
    {
        echo "$trackerKind written=$writtenEvents\n";
    }

    #[\Override]
    public function dropped(string $trackerKind, int $droppedEvents, int $bufferedEvents): void
    {
        echo "$trackerKind dropped=$droppedEvents buffered=$bufferedEvents\n";
    }

    #[\Override]
    public function flushFailed(string $trackerKind, int $bufferedEvents, Throwable $exception): void
    {
        echo "$trackerKind flush_failed buffered=$bufferedEvents\n";
    }
};

$tracker = new ClickHouseExposureTracker(writer: $writer, observer: $observer);
$tracker->trackExposure(new Assignment(experiment: 'checkout', variant: 'green', subjectId: 'visitor-42'));
$tracker->flush();
