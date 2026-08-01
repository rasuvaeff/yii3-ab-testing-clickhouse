<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingClickHouse\Tests\Integration;

use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Test;

/**
 * Exercises the package `config/di.php` (covered by neither cs, psalm, nor the
 * unit suite). Since 2.0 this package owns the schema and does not write, so
 * it binds nothing — the whole contract is that the file stays empty and
 * loadable, not a tracker/middleware wiring surface any more.
 */
#[Test]
#[CoversNothing]
final class ConfigWiringTest
{
    public function packageBindsNothing(): void
    {
        Assert::same(require dirname(__DIR__, 2) . '/config/di.php', []);
    }
}
