<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingClickHouse\Tests;

use Rasuvaeff\Yii3AbTesting\Assignment;
use Rasuvaeff\Yii3AbTesting\ConversionTracker;
use Rasuvaeff\Yii3AbTesting\FlushableTracker;

/**
 * @internal
 */
final class SpyFlushableConversionTracker implements ConversionTracker, FlushableTracker
{
    public int $flushCalls = 0;

    public ?\Throwable $flushThrowable = null;

    #[\Override]
    public function trackConversion(Assignment $assignment, string $goal): void {}

    #[\Override]
    public function flush(): void
    {
        ++$this->flushCalls;

        if ($this->flushThrowable !== null) {
            throw $this->flushThrowable;
        }
    }
}
