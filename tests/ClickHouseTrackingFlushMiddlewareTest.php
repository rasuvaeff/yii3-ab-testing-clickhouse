<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingClickHouse\Tests;

use Psr\Http\Server\MiddlewareInterface;
use Rasuvaeff\Yii3AbTesting\ConversionTracker;
use Rasuvaeff\Yii3AbTesting\ExposureTracker;
use Rasuvaeff\Yii3AbTestingClickHouse\ClickHouseTrackingFlushMiddleware;
use Rasuvaeff\Yii3AbTestingClickHouse\Tests\Support\FakePsrFactory;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(ClickHouseTrackingFlushMiddleware::class)]
final class ClickHouseTrackingFlushMiddlewareTest
{
    public function implementsMiddlewareInterface(): void
    {
        $middleware = new ClickHouseTrackingFlushMiddleware(
            exposureTracker: new SpyFlushableExposureTracker(),
            conversionTracker: new SpyFlushableConversionTracker(),
        );

        Assert::instanceOf($middleware, MiddlewareInterface::class);
    }

    public function returnsHandlerResponseAndFlushesBothTrackers(): void
    {
        $exposureTracker = new SpyFlushableExposureTracker();
        $conversionTracker = new SpyFlushableConversionTracker();
        $middleware = new ClickHouseTrackingFlushMiddleware($exposureTracker, $conversionTracker);
        $request = FakePsrFactory::serverRequest();
        $response = FakePsrFactory::response();
        $handler = FakePsrFactory::handler($response);

        $actual = $middleware->process($request, $handler);

        Assert::same($actual, $response);
        Assert::same($exposureTracker->flushCalls, 1);
        Assert::same($conversionTracker->flushCalls, 1);
    }

    public function flushesBothTrackersEvenWhenHandlerThrows(): void
    {
        $exposureTracker = new SpyFlushableExposureTracker();
        $conversionTracker = new SpyFlushableConversionTracker();
        $middleware = new ClickHouseTrackingFlushMiddleware($exposureTracker, $conversionTracker);
        $request = FakePsrFactory::serverRequest();
        $handler = FakePsrFactory::throwingHandler(new \RuntimeException('boom'));

        try {
            $middleware->process($request, $handler);
            Assert::fail('Expected RuntimeException to be rethrown');
        } catch (\RuntimeException $e) {
            Assert::same($e->getMessage(), 'boom');
        }

        Assert::same($exposureTracker->flushCalls, 1);
        Assert::same($conversionTracker->flushCalls, 1);
    }

    public function swallowsFlushFailuresAndLogsWarnings(): void
    {
        $exposureTracker = new SpyFlushableExposureTracker();
        $exposureTracker->flushThrowable = new \RuntimeException('exposure failed');
        $conversionTracker = new SpyFlushableConversionTracker();
        $conversionTracker->flushThrowable = new \RuntimeException('conversion failed');
        $logger = new SpyLogger();
        $middleware = new ClickHouseTrackingFlushMiddleware($exposureTracker, $conversionTracker, $logger);
        $request = FakePsrFactory::serverRequest();
        $response = FakePsrFactory::response();
        $handler = FakePsrFactory::handler($response);

        $actual = $middleware->process($request, $handler);

        Assert::same($actual, $response);
        Assert::count($logger->warnings, 2);
        Assert::same($logger->warnings[0]['message'], 'Failed to flush ClickHouse A/B testing tracker');
        Assert::same($logger->warnings[0]['context']['trackerKind'], 'exposure');
        Assert::same($logger->warnings[0]['context']['trackerClass'], SpyFlushableExposureTracker::class);
        Assert::instanceOf($logger->warnings[0]['context']['exception'], \RuntimeException::class);
        Assert::same($logger->warnings[1]['context']['trackerKind'], 'conversion');
        Assert::same($logger->warnings[1]['context']['trackerClass'], SpyFlushableConversionTracker::class);
    }

    public function skipsNonFlushableTrackersWithoutCallingFlushOrLogging(): void
    {
        $nonFlushableExposure = new class implements ExposureTracker {
            #[\Override]
            public function trackExposure(\Rasuvaeff\Yii3AbTesting\Assignment $assignment): void {}
        };
        $nonFlushableConversion = new class implements ConversionTracker {
            #[\Override]
            public function trackConversion(\Rasuvaeff\Yii3AbTesting\Assignment $assignment, string $goal): void {}
        };

        $logger = new SpyLogger();
        $middleware = new ClickHouseTrackingFlushMiddleware(
            exposureTracker: $nonFlushableExposure,
            conversionTracker: $nonFlushableConversion,
            logger: $logger,
        );
        $request = FakePsrFactory::serverRequest();
        $response = FakePsrFactory::response();
        $handler = FakePsrFactory::handler($response);

        $actual = $middleware->process($request, $handler);

        Assert::same($actual, $response);
        Assert::same($logger->warnings, []);
    }
}
