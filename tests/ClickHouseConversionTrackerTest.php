<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingClickHouse\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\Yii3AbTesting\Assignment;
use Rasuvaeff\Yii3AbTesting\AssignmentContext;
use Rasuvaeff\Yii3AbTestingClickHouse\ClickHouseConversionTracker;

#[CoversClass(ClickHouseConversionTracker::class)]
final class ClickHouseConversionTrackerTest extends TestCase
{
    private RecordingWriter $writer;

    private ClickHouseConversionTracker $tracker;

    #[\Override]
    protected function setUp(): void
    {
        $this->writer = new RecordingWriter();
        $this->tracker = new ClickHouseConversionTracker(writer: $this->writer);
    }

    #[Test]
    public function flushWritesBufferedConversionRow(): void
    {
        $this->tracker->trackConversion(
            new Assignment(experiment: 'checkout-button', variant: 'green', subjectId: 'user-1'),
            goal: 'purchase',
        );

        $this->tracker->flush();

        $this->assertSame([
            [
                'experiment' => 'checkout-button',
                'variant' => 'green',
                'subject_id' => 'user-1',
                'goal' => 'purchase',
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
        $this->tracker->trackConversion(
            new Assignment(
                experiment: 'exp',
                variant: 'a',
                subjectId: 'u1',
                isForced: true,
                isFallback: true,
                context: AssignmentContext::forEnvironment('staging'),
            ),
            goal: 'signup',
        );
        $this->tracker->flush();

        $this->assertSame([
            [
                'experiment' => 'exp',
                'variant' => 'a',
                'subject_id' => 'u1',
                'goal' => 'signup',
                'is_forced' => 1,
                'is_fallback' => 1,
                'is_sticky' => 0,
                'environment' => 'staging',
            ],
        ], $this->writer->rows);
    }

    #[Test]
    public function flushSendsAllBufferedRowsInOneWrite(): void
    {
        $assignment = new Assignment(experiment: 'exp', variant: 'a', subjectId: 'u1');
        $this->tracker->trackConversion($assignment, goal: 'view');
        $this->tracker->trackConversion($assignment, goal: 'purchase');

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
        $this->tracker->trackConversion(new Assignment(experiment: 'exp', variant: 'a', subjectId: 'u1'), goal: 'view');
        $this->tracker->flush();
        $this->tracker->flush();

        $this->assertSame(1, $this->writer->writeCalls);
        $this->assertCount(1, $this->writer->rows);
    }

    #[Test]
    public function writesStickyFlag(): void
    {
        $this->tracker->trackConversion(
            new Assignment(experiment: 'exp', variant: 'a', subjectId: 'u1', isSticky: true),
            goal: 'purchase',
        );
        $this->tracker->flush();

        $this->assertSame(1, $this->writer->rows[0]['is_sticky']);
    }

    #[Test]
    public function autoFlushWritesWhenBufferReachesThreshold(): void
    {
        $tracker = new ClickHouseConversionTracker(writer: $this->writer, autoFlushSize: 2);
        $assignment = new Assignment(experiment: 'exp', variant: 'a', subjectId: 'u1');

        $tracker->trackConversion($assignment, goal: 'view');
        $this->assertSame(0, $this->writer->writeCalls);

        $tracker->trackConversion($assignment, goal: 'purchase');
        $this->assertSame(1, $this->writer->writeCalls);
        $this->assertCount(2, $this->writer->rows);
    }

    #[Test]
    public function autoFlushesAtTheDefaultThresholdOfOneThousand(): void
    {
        $assignment = new Assignment(experiment: 'exp', variant: 'a', subjectId: 'u1');

        for ($i = 1; $i <= 1000; ++$i) {
            $this->tracker->trackConversion($assignment, goal: 'g' . $i);
        }

        $this->assertSame(1, $this->writer->writeCalls);
        $this->assertCount(1000, $this->writer->rows);
    }

    #[Test]
    public function failedAutoFlushDoesNotThrowAndKeepsEvents(): void
    {
        $failing = new FailingWriter();
        $tracker = new ClickHouseConversionTracker(writer: $failing, autoFlushSize: 2);
        $assignment = new Assignment(experiment: 'exp', variant: 'a', subjectId: 'u1');

        $tracker->trackConversion($assignment, goal: 'view');
        $tracker->trackConversion($assignment, goal: 'purchase');

        $this->assertSame(1, $failing->writeCalls);

        try {
            $tracker->flush();
        } catch (\Rasuvaeff\ClickHouseToolkit\ClickHouseWriteException) {
        }

        $this->assertSame(2, $failing->writeCalls);
    }

    #[Test]
    public function bufferIsCappedAtTenThresholdsWhenWritesKeepFailing(): void
    {
        $flaky = new FlakyWriter();
        $tracker = new ClickHouseConversionTracker(writer: $flaky, autoFlushSize: 1);
        $assignment = new Assignment(experiment: 'exp', variant: 'a', subjectId: 'u1');

        for ($i = 1; $i <= 15; ++$i) {
            $tracker->trackConversion($assignment, goal: 'goal-' . $i);
        }

        $flaky->failing = false;
        $tracker->flush();

        $this->assertCount(10, $flaky->rows);
        $this->assertSame('goal-6', $flaky->rows[0]['goal']);
        $this->assertSame('goal-15', $flaky->rows[9]['goal']);
    }

    #[Test]
    public function throwsOnNonPositiveAutoFlushSize(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Auto-flush size must be at least 1, got -1');

        new ClickHouseConversionTracker(writer: $this->writer, autoFlushSize: -1);
    }
}
