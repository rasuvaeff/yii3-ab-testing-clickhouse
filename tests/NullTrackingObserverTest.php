<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingClickHouse\Tests;

use Rasuvaeff\Yii3AbTestingClickHouse\NullTrackingObserver;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(NullTrackingObserver::class)]
final class NullTrackingObserverTest
{
    public function acceptsEverySignal(): void
    {
        $observer = new NullTrackingObserver();
        $observer->buffered('exposure', 1);
        $observer->written('exposure', 1);
        $observer->dropped('exposure', 1, 10);
        $observer->flushFailed('exposure', 1, new \RuntimeException('failed'));

        Assert::true(true);
    }
}
