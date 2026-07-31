<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingClickHouse;

/**
 * @internal
 */
final readonly class NullTrackingObserver implements TrackingObserverInterface
{
    #[\Override]
    public function buffered(string $trackerKind, int $bufferedEvents): void {}

    #[\Override]
    public function written(string $trackerKind, int $writtenEvents): void {}

    #[\Override]
    public function dropped(string $trackerKind, int $droppedEvents, int $bufferedEvents): void {}

    #[\Override]
    public function flushFailed(string $trackerKind, int $bufferedEvents, \Throwable $exception): void {}
}
