<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingClickHouse\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\Yii3AbTesting\Assignment;
use Rasuvaeff\Yii3AbTesting\AssignmentContext;
use Rasuvaeff\Yii3AbTestingClickHouse\ClickHouseExposureTracker;

#[CoversClass(ClickHouseExposureTracker::class)]
final class ClickHouseExposureTrackerTest extends TestCase
{
    private RecordingWriter $writer;

    private ClickHouseExposureTracker $tracker;

    #[\Override]
    protected function setUp(): void
    {
        $this->writer = new RecordingWriter();
        $this->tracker = new ClickHouseExposureTracker(writer: $this->writer);
    }

    #[Test]
    public function flushWritesBufferedExposureRow(): void
    {
        $this->tracker->trackExposure(new Assignment(experiment: 'checkout-button', variant: 'green', subjectId: 'user-1'));

        $this->tracker->flush();

        $this->assertSame([
            [
                'experiment' => 'checkout-button',
                'variant' => 'green',
                'subject_id' => 'user-1',
                'is_forced' => 0,
                'is_fallback' => 0,
                'is_sticky' => 0,
                'environment' => '',
            ],
        ], $this->writer->rows);
    }

    #[Test]
    public function castsFlagsToIntAndReadsEnvironment(): void
    {
        $context = AssignmentContext::forEnvironment('production');

        $this->tracker->trackExposure(new Assignment(
            experiment: 'exp',
            variant: 'a',
            subjectId: 'u1',
            isForced: true,
            isFallback: true,
            context: $context,
        ));
        $this->tracker->flush();

        $this->assertSame([
            [
                'experiment' => 'exp',
                'variant' => 'a',
                'subject_id' => 'u1',
                'is_forced' => 1,
                'is_fallback' => 1,
                'is_sticky' => 0,
                'environment' => 'production',
            ],
        ], $this->writer->rows);
    }

    #[Test]
    public function flushSendsAllBufferedRowsInOneWrite(): void
    {
        $this->tracker->trackExposure(new Assignment(experiment: 'exp', variant: 'a', subjectId: 'u1'));
        $this->tracker->trackExposure(new Assignment(experiment: 'exp', variant: 'b', subjectId: 'u2'));

        $this->tracker->flush();

        $this->assertSame(1, $this->writer->writeCalls);
        $this->assertCount(2, $this->writer->rows);
    }

    #[Test]
    public function flushWithEmptyBufferDoesNotWrite(): void
    {
        $this->tracker->flush();

        $this->assertSame(0, $this->writer->writeCalls);
    }

    #[Test]
    public function bufferIsClearedAfterFlush(): void
    {
        $this->tracker->trackExposure(new Assignment(experiment: 'exp', variant: 'a', subjectId: 'u1'));
        $this->tracker->flush();
        $this->tracker->flush();

        $this->assertSame(1, $this->writer->writeCalls);
        $this->assertCount(1, $this->writer->rows);
    }

    #[Test]
    public function writesStickyFlag(): void
    {
        $this->tracker->trackExposure(new Assignment(experiment: 'exp', variant: 'a', subjectId: 'u1', isSticky: true));
        $this->tracker->flush();

        $this->assertSame(1, $this->writer->rows[0]['is_sticky']);
    }

    #[Test]
    public function autoFlushWritesWhenBufferReachesThreshold(): void
    {
        $tracker = new ClickHouseExposureTracker(writer: $this->writer, autoFlushSize: 2);

        $tracker->trackExposure(new Assignment(experiment: 'exp', variant: 'a', subjectId: 'u1'));
        $this->assertSame(0, $this->writer->writeCalls);

        $tracker->trackExposure(new Assignment(experiment: 'exp', variant: 'b', subjectId: 'u2'));
        $this->assertSame(1, $this->writer->writeCalls);
        $this->assertCount(2, $this->writer->rows);
    }

    #[Test]
    public function autoFlushesAtTheDefaultThresholdOfOneThousand(): void
    {
        for ($i = 1; $i <= 1000; ++$i) {
            $this->tracker->trackExposure(new Assignment(experiment: 'exp', variant: 'a', subjectId: (string) $i));
        }

        $this->assertSame(1, $this->writer->writeCalls);
        $this->assertCount(1000, $this->writer->rows);
    }

    #[Test]
    public function failedAutoFlushDoesNotThrowAndKeepsEvents(): void
    {
        $failing = new FailingWriter();
        $tracker = new ClickHouseExposureTracker(writer: $failing, autoFlushSize: 2);

        $tracker->trackExposure(new Assignment(experiment: 'exp', variant: 'a', subjectId: 'u1'));
        $tracker->trackExposure(new Assignment(experiment: 'exp', variant: 'b', subjectId: 'u2'));

        $this->assertSame(1, $failing->writeCalls);

        // Events were kept: an explicit flush retries the same buffer.
        try {
            $tracker->flush();
        } catch (\Rasuvaeff\ClickHouseToolkit\ClickHouseWriteException) {
        }

        $this->assertSame(2, $failing->writeCalls);
    }

    #[Test]
    public function failedAutoFlushRetriesAtNextThresholdMultiple(): void
    {
        $failing = new FailingWriter();
        $tracker = new ClickHouseExposureTracker(writer: $failing, autoFlushSize: 2);

        for ($i = 1; $i <= 6; ++$i) {
            $tracker->trackExposure(new Assignment(experiment: 'exp', variant: 'a', subjectId: (string) $i));
        }

        // Attempts at buffer sizes 2, 4 and 6 — not on every event.
        $this->assertSame(3, $failing->writeCalls);
    }

    #[Test]
    public function bufferIsCappedAtTenThresholdsWhenWritesKeepFailing(): void
    {
        $flaky = new FlakyWriter();
        $tracker = new ClickHouseExposureTracker(writer: $flaky, autoFlushSize: 1);

        for ($i = 1; $i <= 15; ++$i) {
            $tracker->trackExposure(new Assignment(experiment: 'exp', variant: 'a', subjectId: (string) $i));
        }

        $flaky->failing = false;
        $tracker->flush();

        // Cap = 10 × autoFlushSize: the 5 oldest of 15 events were dropped.
        $this->assertCount(10, $flaky->rows);
        $this->assertSame('6', $flaky->rows[0]['subject_id']);
        $this->assertSame('15', $flaky->rows[9]['subject_id']);
    }

    #[Test]
    public function throwsOnNonPositiveAutoFlushSize(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Auto-flush size must be at least 1, got 0');

        new ClickHouseExposureTracker(writer: $this->writer, autoFlushSize: 0);
    }
}
