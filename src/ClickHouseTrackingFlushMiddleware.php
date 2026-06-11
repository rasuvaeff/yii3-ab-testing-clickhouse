<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingClickHouse;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Rasuvaeff\Yii3AbTesting\ConversionTracker;
use Rasuvaeff\Yii3AbTesting\ExposureTracker;
use Rasuvaeff\Yii3AbTesting\FlushableTracker;

/**
 * Flushes buffered ClickHouse-backed A/B trackers once per request without
 * ever breaking the request/response flow if analytics storage is down.
 *
 * Place this late in the PSR-15 pipeline so it runs after application code has
 * tracked all exposures/conversions for the request.
 *
 * @api
 */
final readonly class ClickHouseTrackingFlushMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ExposureTracker $exposureTracker,
        private ConversionTracker $conversionTracker,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } finally {
            $this->safeFlush($this->exposureTracker, 'exposure');
            $this->safeFlush($this->conversionTracker, 'conversion');
        }
    }

    private function safeFlush(object $tracker, string $trackerKind): void
    {
        if (!$tracker instanceof FlushableTracker) {
            return;
        }

        try {
            $tracker->flush();
        } catch (\Throwable $e) {
            $this->logger->warning(
                message: 'Failed to flush ClickHouse A/B testing tracker',
                context: [
                    'trackerKind' => $trackerKind,
                    'trackerClass' => $tracker::class,
                    'exception' => $e,
                ],
            );
        }
    }
}
