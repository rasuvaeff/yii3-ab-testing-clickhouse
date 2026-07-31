<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingClickHouse;

/**
 * Receives low-cardinality tracker lifecycle signals. Implementations should
 * increment metrics or emit traces and must not throw.
 *
 * @api
 */
interface TrackingObserverInterface
{
    public function buffered(string $trackerKind, int $bufferedEvents): void;

    public function written(string $trackerKind, int $writtenEvents): void;

    public function dropped(string $trackerKind, int $droppedEvents, int $bufferedEvents): void;

    public function flushFailed(string $trackerKind, int $bufferedEvents, \Throwable $exception): void;
}
