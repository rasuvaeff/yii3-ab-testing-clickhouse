<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingClickHouse\Tests;

use Rasuvaeff\ClickHouseToolkit\ClickHouseWriterInterface;

/**
 * In-memory {@see ClickHouseWriterInterface} that records every written row and
 * the number of `write()` calls.
 *
 * @internal
 */
final class RecordingWriter implements ClickHouseWriterInterface
{
    /**
     * @var list<array<string, mixed>>
     */
    public array $rows = [];

    public int $writeCalls = 0;

    #[\Override]
    public function write(iterable $rows): void
    {
        ++$this->writeCalls;

        foreach ($rows as $row) {
            $this->rows[] = $row;
        }
    }
}
