<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingClickHouse\Tests;

use InvalidArgumentException;
use Rasuvaeff\Yii3AbTestingClickHouse\CompositeTrackingBatchSink;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(CompositeTrackingBatchSink::class)]
final class CompositeTrackingBatchSinkTest
{
    public function fansOutRowsInOrder(): void
    {
        $first = new RecordingBatchSink();
        $second = new RecordingBatchSink();
        $sink = new CompositeTrackingBatchSink([$first, $second]);

        $sink->write([['event' => 'one']]);

        Assert::same($first->rows, [['event' => 'one']]);
        Assert::same($second->rows, [['event' => 'one']]);
    }

    public function rejectsAnEmptySinkList(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new CompositeTrackingBatchSink([]);
    }
}
