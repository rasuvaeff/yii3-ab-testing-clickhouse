<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingClickHouse\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\Yii3AbTesting\ConversionTracker;
use Rasuvaeff\Yii3AbTesting\ExposureTracker;
use Rasuvaeff\Yii3AbTestingClickHouse\ClickHouseConversionTracker;
use Rasuvaeff\Yii3AbTestingClickHouse\ClickHouseExposureTracker;
use Rasuvaeff\Yii3AbTestingClickHouse\Tests\StubClickHouseClient;

/**
 * Exercises the package `config/di.php` (covered by neither cs, psalm, nor the
 * unit suite): builds the real writer via the factory closures with a no-I/O
 * ClickHouse client, and checks the one-source rule against the core package's
 * own vendored `config/di.php` (a key defined by two packages in the `di` group
 * is what triggers `yiisoft/config`'s `Duplicate key` error).
 */
#[CoversNothing]
final class ConfigWiringTest extends TestCase
{
    #[Test]
    public function bindsClickHouseExposureTracker(): void
    {
        $definitions = $this->loadPackage();
        $factory = $definitions[ExposureTracker::class];

        $this->assertInstanceOf(ClickHouseExposureTracker::class, $factory(new StubClickHouseClient()));
    }

    #[Test]
    public function bindsClickHouseConversionTracker(): void
    {
        $definitions = $this->loadPackage();
        $factory = $definitions[ConversionTracker::class];

        $this->assertInstanceOf(ClickHouseConversionTracker::class, $factory(new StubClickHouseClient()));
    }

    #[Test]
    public function packageBindsOnlyTrackerKeys(): void
    {
        $this->assertSame(
            [ExposureTracker::class, ConversionTracker::class],
            array_keys($this->loadPackage()),
        );
    }

    #[Test]
    public function coreAndPackageDoNotShareDiKeys(): void
    {
        $overlap = array_intersect_key($this->loadCore(), $this->loadPackage());

        $this->assertSame([], $overlap, 'core and -clickhouse must not define the same di key');
    }

    /**
     * @return array<string, mixed>
     */
    private function loadPackage(): array
    {
        $params = [];

        return require dirname(__DIR__, 2) . '/config/di.php';
    }

    /**
     * @return array<string, mixed>
     */
    private function loadCore(): array
    {
        $params = [];

        return require dirname(__DIR__, 2) . '/vendor/rasuvaeff/yii3-ab-testing/config/di.php';
    }
}
