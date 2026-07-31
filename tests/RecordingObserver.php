<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingClickHouse\Tests;

use Rasuvaeff\Yii3AbTestingClickHouse\TrackingObserverInterface;

/**
 * @internal
 */
final class RecordingObserver implements TrackingObserverInterface
{
    /**
     * @var list<array{signal: string, trackerKind: string, count: int, bufferedEvents?: int, exception?: \Throwable}>
     */
    public array $signals = [];

    #[\Override]
    public function buffered(string $trackerKind, int $bufferedEvents): void
    {
        $this->signals[] = ['signal' => 'buffered', 'trackerKind' => $trackerKind, 'count' => $bufferedEvents];
    }

    #[\Override]
    public function written(string $trackerKind, int $writtenEvents): void
    {
        $this->signals[] = ['signal' => 'written', 'trackerKind' => $trackerKind, 'count' => $writtenEvents];
    }

    #[\Override]
    public function dropped(string $trackerKind, int $droppedEvents, int $bufferedEvents): void
    {
        $this->signals[] = [
            'signal' => 'dropped',
            'trackerKind' => $trackerKind,
            'count' => $droppedEvents,
            'bufferedEvents' => $bufferedEvents,
        ];
    }

    #[\Override]
    public function flushFailed(string $trackerKind, int $bufferedEvents, \Throwable $exception): void
    {
        $this->signals[] = [
            'signal' => 'flush_failed',
            'trackerKind' => $trackerKind,
            'count' => $bufferedEvents,
            'exception' => $exception,
        ];
    }
}
