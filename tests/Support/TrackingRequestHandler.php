<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingClickHouse\Tests\Support;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rasuvaeff\Yii3AbTesting\Assignment;
use Rasuvaeff\Yii3AbTesting\ConversionTracker;
use Rasuvaeff\Yii3AbTesting\ExposureTracker;

/**
 * @internal
 */
final readonly class TrackingRequestHandler implements RequestHandlerInterface
{
    public function __construct(
        private ExposureTracker $exposureTracker,
        private ConversionTracker $conversionTracker,
        private Assignment $assignment,
        private ResponseInterface $response,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->exposureTracker->trackExposure($this->assignment);
        $this->conversionTracker->trackConversion($this->assignment, goal: 'purchase');

        return $this->response;
    }
}
