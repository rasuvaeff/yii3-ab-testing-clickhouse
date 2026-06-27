<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingClickHouse\Tests\Integration;

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
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Test;

/**
 * Exercises the package `config/di.php` (covered by neither cs, psalm, nor the
 * unit suite): builds the real writer via the factory closures with a
 * no-network toolkit client factory, and checks the one-source rule against the
 * core package's own vendored `config/di.php` (a key defined by two packages in
 * the `di` group is what triggers `yiisoft/config`'s `Duplicate key` error).
 */
#[Test]
#[CoversNothing]
final class ConfigWiringTest
{
    public function bindsFlushMiddleware(): void
    {
        $definitions = $this->loadPackage();
        $factory = $definitions[ClickHouseTrackingFlushMiddleware::class];

        Assert::instanceOf(
            $factory(new SpyFlushableExposureTracker(), new SpyFlushableConversionTracker()),
            ClickHouseTrackingFlushMiddleware::class,
        );
    }

    public function bindsClickHouseExposureTracker(): void
    {
        $definitions = $this->loadPackage();
        $factory = $definitions[ExposureTracker::class];

        Assert::instanceOf($factory($this->createClientFactory()), ClickHouseExposureTracker::class);
    }

    public function bindsClickHouseConversionTracker(): void
    {
        $definitions = $this->loadPackage();
        $factory = $definitions[ConversionTracker::class];

        Assert::instanceOf($factory($this->createClientFactory()), ClickHouseConversionTracker::class);
    }

    public function packageBindsOnlyTrackerAndMiddlewareKeys(): void
    {
        Assert::same(
            array_keys($this->loadPackage()),
            [ClickHouseTrackingFlushMiddleware::class, ExposureTracker::class, ConversionTracker::class],
        );
    }

    public function coreAndPackageDoNotShareDiKeys(): void
    {
        $overlap = array_intersect_key($this->loadCore(), $this->loadPackage());

        Assert::same($overlap, [], 'core and -clickhouse must not define the same di key');
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
        $fakeClient = new class implements ClientInterface {
            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                throw new \RuntimeException('No network in unit tests');
            }
        };

        return new ClickHouseClientFactory(
            config: new ClickHouseConfig(host: '127.0.0.1', port: 8123),
            httpClient: $fakeClient,
            requestFactory: new \GuzzleHttp\Psr7\HttpFactory(),
            streamFactory: new \GuzzleHttp\Psr7\HttpFactory(),
            uriFactory: new \GuzzleHttp\Psr7\HttpFactory(),
        );
    }
}
