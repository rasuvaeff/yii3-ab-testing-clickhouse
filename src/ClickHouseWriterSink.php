<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingClickHouse;

use Rasuvaeff\ClickHouseToolkit\ClickHouseWriterInterface;

/**
 * @internal
 */
final readonly class ClickHouseWriterSink implements TrackingBatchSinkInterface
{
    public function __construct(
        private ClickHouseWriterInterface $writer,
    ) {}

    #[\Override]
    public function write(array $rows): void
    {
        $this->writer->write($rows);
    }
}
