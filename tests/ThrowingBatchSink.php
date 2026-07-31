<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingClickHouse\Tests;

use Rasuvaeff\Yii3AbTestingClickHouse\TrackingBatchSinkInterface;

final readonly class ThrowingBatchSink implements TrackingBatchSinkInterface
{
    #[\Override]
    public function write(array $rows): void
    {
        throw new \RuntimeException('Secondary sink failed');
    }
}
