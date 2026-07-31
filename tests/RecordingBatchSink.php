<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingClickHouse\Tests;

use Rasuvaeff\Yii3AbTestingClickHouse\TrackingBatchSinkInterface;

/**
 * @internal
 */
final class RecordingBatchSink implements TrackingBatchSinkInterface
{
    /**
     * @var list<array<string, mixed>>
     */
    public array $rows = [];

    #[\Override]
    public function write(array $rows): void
    {
        $this->rows = [...$this->rows, ...$rows];
    }
}
