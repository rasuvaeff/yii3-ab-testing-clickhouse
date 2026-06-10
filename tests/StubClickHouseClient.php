<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingClickHouse\Tests;

use Psr\Http\Message\StreamInterface;
use SimPod\ClickHouseClient\Client\ClickHouseClient;
use SimPod\ClickHouseClient\Format\Format;
use SimPod\ClickHouseClient\Output\Output;
use SimPod\ClickHouseClient\Schema\Table;

/**
 * A {@see ClickHouseClient} that performs no I/O. Used by the DI wiring test,
 * which only needs to construct a writer, never to talk to a server.
 *
 * @internal
 */
final class StubClickHouseClient implements ClickHouseClient
{
    #[\Override]
    public function executeQuery(string $query, array $settings = []): void {}

    #[\Override]
    public function executeQueryWithParams(string $query, array $params, array $settings = []): void {}

    #[\Override]
    public function select(string $query, Format $outputFormat, array $settings = []): Output
    {
        throw new \BadMethodCallException('not implemented');
    }

    #[\Override]
    public function selectWithParams(string $query, array $params, Format $outputFormat, array $settings = []): Output
    {
        throw new \BadMethodCallException('not implemented');
    }

    #[\Override]
    public function insert(Table|string $table, array $values, ?array $columns = null, array $settings = []): void {}

    #[\Override]
    public function insertWithFormat(Table|string $table, Format $inputFormat, string $data, array $settings = []): void {}

    #[\Override]
    public function insertPayload(Table|string $table, Format $inputFormat, StreamInterface $payload, array $columns = [], array $settings = []): void {}
}
