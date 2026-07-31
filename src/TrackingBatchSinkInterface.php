<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingClickHouse;

/**
 * Internal write boundary. A future schema-v2 sink may transform the normalized
 * v1 row before writing, while buffering and delivery signals stay unchanged.
 *
 * @internal
 */
interface TrackingBatchSinkInterface
{
    /**
     * @param non-empty-list<array<string, mixed>> $rows
     */
    public function write(array $rows): void;
}
