<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingClickHouse\Tests;

use Rasuvaeff\ClickHouseToolkit\ClickHouseWriteException;
use Rasuvaeff\ClickHouseToolkit\ClickHouseWriterInterface;

/**
 * {@see ClickHouseWriterInterface} whose every write fails, mimicking a down
 * ClickHouse, to exercise the non-fatal auto-flush branches of the trackers.
 *
 * @internal
 */
final class FailingWriter implements ClickHouseWriterInterface
{
    public int $writeCalls = 0;

    #[\Override]
    public function write(iterable $rows): void
    {
        ++$this->writeCalls;

        throw new ClickHouseWriteException('ClickHouse is down');
    }
}
