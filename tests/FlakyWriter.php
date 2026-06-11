<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingClickHouse\Tests;

use Rasuvaeff\ClickHouseToolkit\ClickHouseWriteException;
use Rasuvaeff\ClickHouseToolkit\ClickHouseWriterInterface;

/**
 * {@see ClickHouseWriterInterface} that fails while {@see $failing} is true and
 * records rows once it recovers, to observe tracker buffer state across a
 * ClickHouse outage.
 *
 * @internal
 */
final class FlakyWriter implements ClickHouseWriterInterface
{
    public bool $failing = true;

    /**
     * @var list<array<string, mixed>>
     */
    public array $rows = [];

    #[\Override]
    public function write(iterable $rows): void
    {
        if ($this->failing) {
            throw new ClickHouseWriteException('ClickHouse is down');
        }

        foreach ($rows as $row) {
            $this->rows[] = $row;
        }
    }
}
