<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingClickHouse\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rasuvaeff\Yii3AbTesting\ConversionTracker;
use Rasuvaeff\Yii3AbTesting\ExposureTracker;
use Rasuvaeff\Yii3AbTestingClickHouse\ClickHouseTrackingFlushMiddleware;

#[CoversClass(ClickHouseTrackingFlushMiddleware::class)]
final class ClickHouseTrackingFlushMiddlewareTest extends TestCase
{
    #[Test]
    public function implementsMiddlewareInterface(): void
    {
        $middleware = new ClickHouseTrackingFlushMiddleware(
            exposureTracker: new SpyFlushableExposureTracker(),
            conversionTracker: new SpyFlushableConversionTracker(),
        );

        $this->assertInstanceOf(MiddlewareInterface::class, $middleware);
    }

    #[Test]
    public function returnsHandlerResponseAndFlushesBothTrackers(): void
    {
        $exposureTracker = new SpyFlushableExposureTracker();
        $conversionTracker = new SpyFlushableConversionTracker();
        $middleware = new ClickHouseTrackingFlushMiddleware($exposureTracker, $conversionTracker);
        $request = $this->createMock(ServerRequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $handler = new class ($response) implements RequestHandlerInterface {
            public function __construct(
                private readonly ResponseInterface $response,
            ) {}

            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };

        $actual = $middleware->process($request, $handler);

        $this->assertSame($response, $actual);
        $this->assertSame(1, $exposureTracker->flushCalls);
        $this->assertSame(1, $conversionTracker->flushCalls);
    }

    #[Test]
    public function flushesBothTrackersEvenWhenHandlerThrows(): void
    {
        $exposureTracker = new SpyFlushableExposureTracker();
        $conversionTracker = new SpyFlushableConversionTracker();
        $middleware = new ClickHouseTrackingFlushMiddleware($exposureTracker, $conversionTracker);
        $request = $this->createMock(ServerRequestInterface::class);
        $handler = new class implements RequestHandlerInterface {
            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \RuntimeException('boom');
            }
        };

        try {
            $middleware->process($request, $handler);
            $this->fail('Expected RuntimeException to be rethrown');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame(1, $exposureTracker->flushCalls);
        $this->assertSame(1, $conversionTracker->flushCalls);
    }

    #[Test]
    public function swallowsFlushFailuresAndLogsWarnings(): void
    {
        $exposureTracker = new SpyFlushableExposureTracker();
        $exposureTracker->flushThrowable = new \RuntimeException('exposure failed');
        $conversionTracker = new SpyFlushableConversionTracker();
        $conversionTracker->flushThrowable = new \RuntimeException('conversion failed');
        $logger = new SpyLogger();
        $middleware = new ClickHouseTrackingFlushMiddleware($exposureTracker, $conversionTracker, $logger);
        $request = $this->createMock(ServerRequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $handler = new class ($response) implements RequestHandlerInterface {
            public function __construct(
                private readonly ResponseInterface $response,
            ) {}

            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };

        $actual = $middleware->process($request, $handler);

        $this->assertSame($response, $actual);
        $this->assertCount(2, $logger->warnings);
        $this->assertSame('Failed to flush ClickHouse A/B testing tracker', $logger->warnings[0]['message']);
        $this->assertSame('exposure', $logger->warnings[0]['context']['trackerKind']);
        $this->assertSame(SpyFlushableExposureTracker::class, $logger->warnings[0]['context']['trackerClass']);
        $this->assertInstanceOf(\RuntimeException::class, $logger->warnings[0]['context']['exception']);
        $this->assertSame('conversion', $logger->warnings[1]['context']['trackerKind']);
        $this->assertSame(SpyFlushableConversionTracker::class, $logger->warnings[1]['context']['trackerClass']);
    }

    #[Test]
    public function skipsNonFlushableTrackers(): void
    {
        $middleware = new ClickHouseTrackingFlushMiddleware(
            exposureTracker: $this->createMock(ExposureTracker::class),
            conversionTracker: $this->createMock(ConversionTracker::class),
        );
        $request = $this->createMock(ServerRequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $handler = new class ($response) implements RequestHandlerInterface {
            public function __construct(
                private readonly ResponseInterface $response,
            ) {}

            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };

        $actual = $middleware->process($request, $handler);

        $this->assertSame($response, $actual);
    }
}
