<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingClickHouse;

use InvalidArgumentException;

/**
 * Sequential fan-out boundary prepared for an opt-in schema-v2 target. It is
 * intentionally not wired by the package DI in the v1 line.
 *
 * @internal
 */
final readonly class CompositeTrackingBatchSink implements TrackingBatchSinkInterface
{
    /**
     * @var non-empty-list<TrackingBatchSinkInterface>
     */
    private array $sinks;

    /**
     * @param iterable<TrackingBatchSinkInterface> $sinks
     */
    public function __construct(iterable $sinks)
    {
        $resolved = [];

        foreach ($sinks as $sink) {
            $resolved[] = $sink;
        }

        if ($resolved === []) {
            throw new InvalidArgumentException('At least one tracking batch sink is required');
        }

        $this->sinks = $resolved;
    }

    #[\Override]
    public function write(array $rows): void
    {
        foreach ($this->sinks as $sink) {
            $sink->write($rows);
        }
    }
}
