<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingClickHouse\Tests;

use InvalidArgumentException;
use Rasuvaeff\Yii3AbTesting\Assignment;
use Rasuvaeff\Yii3AbTesting\AssignmentContext;
use Rasuvaeff\Yii3AbTestingClickHouse\ClickHouseExposureTracker;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(ClickHouseExposureTracker::class)]
final class ClickHouseExposureTrackerTest
{
    private RecordingWriter $writer;

    private ClickHouseExposureTracker $tracker;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->writer = new RecordingWriter();
        $this->tracker = new ClickHouseExposureTracker(writer: $this->writer);
    }

    public function flushWritesBufferedExposureRow(): void
    {
        $this->tracker->trackExposure(new Assignment(experiment: 'checkout-button', variant: 'green', subjectId: 'user-1'));

        $this->tracker->flush();

        Assert::same($this->writer->rows, [
            [
                'experiment' => 'checkout-button',
                'variant' => 'green',
                'subject_id' => 'user-1',
                'is_forced' => 0,
                'is_fallback' => 0,
                'is_sticky' => 0,
                'environment' => '',
            ],
        ]);
    }

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

        Assert::same($this->writer->rows, [
            [
                'experiment' => 'exp',
                'variant' => 'a',
                'subject_id' => 'u1',
                'is_forced' => 1,
                'is_fallback' => 1,
                'is_sticky' => 0,
                'environment' => 'production',
            ],
        ]);
    }

    public function flushSendsAllBufferedRowsInOneWrite(): void
    {
        $this->tracker->trackExposure(new Assignment(experiment: 'exp', variant: 'a', subjectId: 'u1'));
        $this->tracker->trackExposure(new Assignment(experiment: 'exp', variant: 'b', subjectId: 'u2'));

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
        $this->tracker->trackExposure(new Assignment(experiment: 'exp', variant: 'a', subjectId: 'u1'));
        $this->tracker->flush();
        $this->tracker->flush();

        Assert::same($this->writer->writeCalls, 1);
        Assert::count($this->writer->rows, 1);
    }

    public function writesStickyFlag(): void
    {
        $this->tracker->trackExposure(new Assignment(experiment: 'exp', variant: 'a', subjectId: 'u1', isSticky: true));
        $this->tracker->flush();

        Assert::same($this->writer->rows[0]['is_sticky'], 1);
    }

    public function autoFlushWritesWhenBufferReachesThreshold(): void
    {
        $tracker = new ClickHouseExposureTracker(writer: $this->writer, autoFlushSize: 2);

        $tracker->trackExposure(new Assignment(experiment: 'exp', variant: 'a', subjectId: 'u1'));
        Assert::same($this->writer->writeCalls, 0);

        $tracker->trackExposure(new Assignment(experiment: 'exp', variant: 'b', subjectId: 'u2'));
        Assert::same($this->writer->writeCalls, 1);
        Assert::count($this->writer->rows, 2);
    }

    public function autoFlushesAtTheDefaultThresholdOfOneThousand(): void
    {
        for ($i = 1; $i <= 1000; ++$i) {
            $this->tracker->trackExposure(new Assignment(experiment: 'exp', variant: 'a', subjectId: (string) $i));
        }

        Assert::same($this->writer->writeCalls, 1);
        Assert::count($this->writer->rows, 1000);
    }

    public function failedAutoFlushDoesNotThrowAndKeepsEvents(): void
    {
        $failing = new FailingWriter();
        $tracker = new ClickHouseExposureTracker(writer: $failing, autoFlushSize: 2);

        $tracker->trackExposure(new Assignment(experiment: 'exp', variant: 'a', subjectId: 'u1'));
        $tracker->trackExposure(new Assignment(experiment: 'exp', variant: 'b', subjectId: 'u2'));

        Assert::same($failing->writeCalls, 1);

        try {
            $tracker->flush();
        } catch (\Rasuvaeff\ClickHouseToolkit\ClickHouseWriteException) {
        }

        Assert::same($failing->writeCalls, 2);
    }

    public function failedAutoFlushRetriesAtNextThresholdMultiple(): void
    {
        $failing = new FailingWriter();
        $tracker = new ClickHouseExposureTracker(writer: $failing, autoFlushSize: 2);

        for ($i = 1; $i <= 6; ++$i) {
            $tracker->trackExposure(new Assignment(experiment: 'exp', variant: 'a', subjectId: (string) $i));
        }

        Assert::same($failing->writeCalls, 3);
    }

    public function bufferIsCappedAtTenThresholdsWhenWritesKeepFailing(): void
    {
        $flaky = new FlakyWriter();
        $tracker = new ClickHouseExposureTracker(writer: $flaky, autoFlushSize: 1);

        for ($i = 1; $i <= 15; ++$i) {
            $tracker->trackExposure(new Assignment(experiment: 'exp', variant: 'a', subjectId: (string) $i));
        }

        $flaky->failing = false;
        $tracker->flush();

        Assert::count($flaky->rows, 10);
        Assert::same($flaky->rows[0]['subject_id'], '6');
        Assert::same($flaky->rows[9]['subject_id'], '15');
    }

    public function throwsOnNonPositiveAutoFlushSize(): void
    {
        try {
            new ClickHouseExposureTracker(writer: $this->writer, autoFlushSize: 0);
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('Auto-flush size must be at least 1, got 0');
        }
    }
}
