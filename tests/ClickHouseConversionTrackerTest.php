<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingClickHouse\Tests;

use InvalidArgumentException;
use Rasuvaeff\Yii3AbTesting\Assignment;
use Rasuvaeff\Yii3AbTesting\AssignmentContext;
use Rasuvaeff\Yii3AbTestingClickHouse\ClickHouseConversionTracker;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(ClickHouseConversionTracker::class)]
final class ClickHouseConversionTrackerTest
{
    private RecordingWriter $writer;

    private ClickHouseConversionTracker $tracker;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->writer = new RecordingWriter();
        $this->tracker = new ClickHouseConversionTracker(writer: $this->writer);
    }

    public function flushWritesBufferedConversionRow(): void
    {
        $this->tracker->trackConversion(
            new Assignment(experiment: 'checkout-button', variant: 'green', subjectId: 'user-1'),
            goal: 'purchase',
        );

        $this->tracker->flush();

        Assert::same($this->writer->rows, [
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
        ]);
    }

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

        Assert::same($this->writer->rows, [
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
        ]);
    }

    public function flushSendsAllBufferedRowsInOneWrite(): void
    {
        $assignment = new Assignment(experiment: 'exp', variant: 'a', subjectId: 'u1');
        $this->tracker->trackConversion($assignment, goal: 'view');
        $this->tracker->trackConversion($assignment, goal: 'purchase');

        $this->tracker->flush();

        Assert::same($this->writer->writeCalls, 1);
        Assert::count($this->writer->rows, 2);
    }

    public function flushWithEmptyBufferDoesNotWrite(): void
    {
        $this->tracker->flush();

        Assert::same($this->writer->writeCalls, 0);
    }

    public function bufferIsClearedAfterFlush(): void
    {
        $this->tracker->trackConversion(new Assignment(experiment: 'exp', variant: 'a', subjectId: 'u1'), goal: 'view');
        $this->tracker->flush();
        $this->tracker->flush();

        Assert::same($this->writer->writeCalls, 1);
        Assert::count($this->writer->rows, 1);
    }

    public function writesStickyFlag(): void
    {
        $this->tracker->trackConversion(
            new Assignment(experiment: 'exp', variant: 'a', subjectId: 'u1', isSticky: true),
            goal: 'purchase',
        );
        $this->tracker->flush();

        Assert::same($this->writer->rows[0]['is_sticky'], 1);
    }

    public function autoFlushWritesWhenBufferReachesThreshold(): void
    {
        $tracker = new ClickHouseConversionTracker(writer: $this->writer, autoFlushSize: 2);
        $assignment = new Assignment(experiment: 'exp', variant: 'a', subjectId: 'u1');

        $tracker->trackConversion($assignment, goal: 'view');
        Assert::same($this->writer->writeCalls, 0);

        $tracker->trackConversion($assignment, goal: 'purchase');
        Assert::same($this->writer->writeCalls, 1);
        Assert::count($this->writer->rows, 2);
    }

    public function autoFlushesAtTheDefaultThresholdOfOneThousand(): void
    {
        $assignment = new Assignment(experiment: 'exp', variant: 'a', subjectId: 'u1');

        for ($i = 1; $i <= 1000; ++$i) {
            $this->tracker->trackConversion($assignment, goal: 'g' . $i);
        }

        Assert::same($this->writer->writeCalls, 1);
        Assert::count($this->writer->rows, 1000);
    }

    public function failedAutoFlushDoesNotThrowAndKeepsEvents(): void
    {
        $failing = new FailingWriter();
        $tracker = new ClickHouseConversionTracker(writer: $failing, autoFlushSize: 2);
        $assignment = new Assignment(experiment: 'exp', variant: 'a', subjectId: 'u1');

        $tracker->trackConversion($assignment, goal: 'view');
        $tracker->trackConversion($assignment, goal: 'purchase');

        Assert::same($failing->writeCalls, 1);

        try {
            $tracker->flush();
        } catch (\Rasuvaeff\ClickHouseToolkit\ClickHouseWriteException) {
        }

        Assert::same($failing->writeCalls, 2);
    }

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

        Assert::count($flaky->rows, 10);
        Assert::same($flaky->rows[0]['goal'], 'goal-6');
        Assert::same($flaky->rows[9]['goal'], 'goal-15');
    }

    public function throwsOnNonPositiveAutoFlushSize(): void
    {
        try {
            new ClickHouseConversionTracker(writer: $this->writer, autoFlushSize: -1);
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('Auto-flush size must be at least 1, got -1');
        }
    }
}
