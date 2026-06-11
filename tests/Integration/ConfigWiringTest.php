<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingClickHouse\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Rasuvaeff\ClickHouseToolkit\ClickHouseClientFactory;
use Rasuvaeff\ClickHouseToolkit\ClickHouseConfig;
use Rasuvaeff\Yii3AbTesting\ConversionTracker;
use Rasuvaeff\Yii3AbTesting\ExposureTracker;
use Rasuvaeff\Yii3AbTestingClickHouse\ClickHouseConversionTracker;
use Rasuvaeff\Yii3AbTestingClickHouse\ClickHouseExposureTracker;
use Rasuvaeff\Yii3AbTestingClickHouse\ClickHouseTrackingFlushMiddleware;
use Rasuvaeff\Yii3AbTestingClickHouse\Tests\SpyFlushableConversionTracker;
use Rasuvaeff\Yii3AbTestingClickHouse\Tests\SpyFlushableExposureTracker;

/**
 * Exercises the package `config/di.php` (covered by neither cs, psalm, nor the
 * unit suite): builds the real writer via the factory closures with a
 * no-network toolkit client factory, and checks the one-source rule against the
 * core package's own vendored `config/di.php` (a key defined by two packages in
 * the `di` group is what triggers `yiisoft/config`'s `Duplicate key` error).
 */
#[CoversNothing]
final class ConfigWiringTest extends TestCase
{
    #[Test]
    public function bindsFlushMiddleware(): void
    {
        $definitions = $this->loadPackage();
        $factory = $definitions[ClickHouseTrackingFlushMiddleware::class];

        $this->assertInstanceOf(
            ClickHouseTrackingFlushMiddleware::class,
            $factory(new SpyFlushableExposureTracker(), new SpyFlushableConversionTracker()),
        );
    }

    #[Test]
    public function bindsClickHouseExposureTracker(): void
    {
        $definitions = $this->loadPackage();
        $factory = $definitions[ExposureTracker::class];

        $this->assertInstanceOf(ClickHouseExposureTracker::class, $factory($this->createClientFactory()));
    }

    #[Test]
    public function bindsClickHouseConversionTracker(): void
    {
        $definitions = $this->loadPackage();
        $factory = $definitions[ConversionTracker::class];

        $this->assertInstanceOf(ClickHouseConversionTracker::class, $factory($this->createClientFactory()));
    }

    #[Test]
    public function packageBindsOnlyTrackerAndMiddlewareKeys(): void
    {
        $this->assertSame(
            [ClickHouseTrackingFlushMiddleware::class, ExposureTracker::class, ConversionTracker::class],
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

    private function createClientFactory(): ClickHouseClientFactory
    {
        return new ClickHouseClientFactory(
            config: new ClickHouseConfig(host: '127.0.0.1', port: 8123),
            httpClient: $this->createMock(ClientInterface::class),
            requestFactory: new \GuzzleHttp\Psr7\HttpFactory(),
            streamFactory: new \GuzzleHttp\Psr7\HttpFactory(),
            uriFactory: new \GuzzleHttp\Psr7\HttpFactory(),
        );
    }
}
