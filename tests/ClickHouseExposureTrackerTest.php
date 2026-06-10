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
}
