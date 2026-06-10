<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingClickHouse;

use Rasuvaeff\ClickHouseToolkit\ClickHouseWriterInterface;
use Rasuvaeff\Yii3AbTesting\Assignment;
use Rasuvaeff\Yii3AbTesting\ExposureTracker;

/**
 * Buffers exposures in memory and writes them to ClickHouse on {@see flush()}.
 *
 * Tracking never blocks the request — call {@see flush()} once at request end
 * (PSR-15 terminate / shutdown). The injected {@see ClickHouseWriterInterface}
 * (e.g. a {@see \Rasuvaeff\ClickHouseToolkit\ClickHouseBatchWriter}) must target
 * the exposures table with exactly {@see COLUMNS} in this order; the `ts` column
 * is filled server-side by a `DEFAULT now()`.
 *
 * @api
 */
final class ClickHouseExposureTracker implements ExposureTracker
{
    /**
     * Insert columns, in order. The exposures table also has a server-defaulted
     * `ts` column that is intentionally not written here.
     *
     * @var list<string>
     */
    public const array COLUMNS = ['experiment', 'variant', 'subject_id', 'is_forced', 'is_fallback', 'environment'];

    /**
     * @var list<array<string, mixed>>
     */
    private array $buffer = [];

    public function __construct(
        private readonly ClickHouseWriterInterface $writer,
    ) {}

    #[\Override]
    public function trackExposure(Assignment $assignment): void
    {
        $this->buffer[] = [
            'experiment' => $assignment->experiment,
            'variant' => $assignment->variant,
            'subject_id' => $assignment->subjectId,
            'is_forced' => (int) $assignment->isForced,
            'is_fallback' => (int) $assignment->isFallback,
            'environment' => $assignment->context?->getEnvironment() ?? '',
        ];
    }

    /**
     * Writes and clears the buffer. A failed write keeps the buffer intact so the
     * caller may retry.
     *
     * @throws \Rasuvaeff\ClickHouseToolkit\ClickHouseWriteException
     */
    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        $this->writer->write($this->buffer);
        $this->buffer = [];
    }
}
