<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingClickHouse;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Rasuvaeff\ClickHouseToolkit\ClickHouseWriteException;
use Rasuvaeff\ClickHouseToolkit\ClickHouseWriterInterface;
use Rasuvaeff\Yii3AbTesting\Assignment;
use Rasuvaeff\Yii3AbTesting\ExposureTracker;
use Rasuvaeff\Yii3AbTesting\FlushableTracker;

/**
 * Buffers exposures in memory and writes them to ClickHouse on {@see flush()}
 * or automatically once the buffer reaches the auto-flush threshold, so a
 * long-running worker that never calls `flush()` cannot grow the buffer
 * unboundedly. A failed automatic flush never throws into the request: the
 * events are kept and retried at the next threshold multiple, with the buffer
 * capped at ten thresholds (oldest events dropped).
 *
 * Call {@see flush()} once at request end (PSR-15 terminate / shutdown). The
 * injected {@see ClickHouseWriterInterface} (e.g. a
 * {@see \Rasuvaeff\ClickHouseToolkit\ClickHouseBatchWriter}) must target the
 * exposures table with exactly {@see COLUMNS} in this order; the `ts` column is
 * filled server-side by a `DEFAULT now()`.
 *
 * @api
 */
final class ClickHouseExposureTracker implements ExposureTracker, FlushableTracker
{
    /**
     * Insert columns, in order. The exposures table also has a server-defaulted
     * `ts` column that is intentionally not written here.
     *
     * @var list<string>
     */
    public const array COLUMNS = ['experiment', 'variant', 'subject_id', 'is_forced', 'is_fallback', 'is_sticky', 'environment'];

    /**
     * @var list<array<string, mixed>>
     */
    private array $buffer = [];

    private readonly TrackingBatchSinkInterface $sink;

    public function __construct(
        ClickHouseWriterInterface $writer,
        private readonly int $autoFlushSize = 1000,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly TrackingObserverInterface $observer = new NullTrackingObserver(),
        ?TrackingBatchSinkInterface $secondarySink = null,
    ) {
        if ($autoFlushSize < 1) {
            throw new \InvalidArgumentException(sprintf('Auto-flush size must be at least 1, got %d', $autoFlushSize));
        }

        $primarySink = new ClickHouseWriterSink($writer);
        $this->sink = $secondarySink instanceof TrackingBatchSinkInterface
            ? new CompositeTrackingBatchSink([$primarySink, $secondarySink])
            : $primarySink;
    }

    #[\Override]
    public function trackExposure(Assignment $assignment): void
    {
        $this->buffer[] = [
            'experiment' => $assignment->experiment,
            'variant' => $assignment->variant,
            'subject_id' => $assignment->subjectId,
            'is_forced' => (int) $assignment->isForced,
            'is_fallback' => (int) $assignment->isFallback,
            'is_sticky' => (int) $assignment->isSticky,
            'environment' => $assignment->context?->getEnvironment() ?? '',
        ];
        $this->observer->buffered('exposure', \count($this->buffer));

        $this->autoFlush();
    }

    /**
     * Writes and clears the buffer. A failed write keeps the buffer intact so the
     * caller may retry.
     *
     * @throws ClickHouseWriteException
     */
    #[\Override]
    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        $writtenEvents = \count($this->buffer);

        try {
            $this->sink->write($this->buffer);
        } catch (\Throwable $e) {
            $this->observer->flushFailed('exposure', $writtenEvents, $e);

            throw $e;
        }

        $this->buffer = [];
        $this->observer->written('exposure', $writtenEvents);
    }

    private function autoFlush(): void
    {
        if (\count($this->buffer) % $this->autoFlushSize !== 0) {
            return;
        }

        try {
            $this->flush();
        } catch (\Throwable $e) {
            // A primary or opt-in secondary sink failure must not break the request: keep the events
            // and retry at the next threshold multiple, but cap memory by
            // dropping the oldest events beyond ten thresholds.
            $bufferedEvents = \count($this->buffer);
            $this->logger->warning(
                message: 'Failed to auto-flush ClickHouse A/B testing tracker',
                context: [
                    'event' => 'flush_failed',
                    'trackerKind' => 'exposure',
                    'bufferedEvents' => $bufferedEvents,
                    'exception' => $e,
                ],
            );

            $max = $this->autoFlushSize * 10;

            if ($bufferedEvents > $max) {
                $droppedEvents = $bufferedEvents - $max;
                $this->buffer = \array_slice($this->buffer, -$max);
                $this->observer->dropped('exposure', $droppedEvents, $max);
                $this->logger->warning(
                    message: 'Dropped ClickHouse A/B testing events after repeated flush failures',
                    context: [
                        'event' => 'dropped',
                        'trackerKind' => 'exposure',
                        'droppedEvents' => $droppedEvents,
                        'bufferedEvents' => $max,
                    ],
                );
            }
        }
    }
}
