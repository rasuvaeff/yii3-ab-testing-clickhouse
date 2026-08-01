<?php

declare(strict_types=1);

/**
 * This package owns the analytics schema and reads from it; it does not write.
 *
 * It therefore binds nothing. In 1.x it bound `ExposureTracker` and
 * `ConversionTracker`, which made installing it alongside the outbox adapter a
 * `yiisoft/config` `Duplicate key` error; that whole class of conflict is gone
 * with the writers.
 *
 * Migrations still need their table names, which come from
 * `'rasuvaeff/yii3-clickhouse-toolkit' => ['migrationPlaceholders' => …]`
 * because `yiisoft/config` lets exactly one vendor package define a params key.
 */
return [];
