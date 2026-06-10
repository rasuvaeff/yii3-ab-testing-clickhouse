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
}
