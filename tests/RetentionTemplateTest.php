<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingClickHouse\Tests;

use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Test;

#[Test]
#[CoversNothing]
final class RetentionTemplateTest
{
    private const string RETENTION_DIR = __DIR__ . '/../retention';

    public function enableTemplatesRequireTableAndPositiveDayPlaceholders(): void
    {
        foreach (['exposures', 'conversions'] as $kind) {
            $sql = $this->read('enable_' . $kind . '_ttl.sql');

            Assert::string($sql)->contains('{{' . $kind . '_table}}');
            Assert::string($sql)->contains('INTERVAL {{retention_days}} DAY');
        }
    }

    public function disableTemplatesRemoveThePolicyWithoutDroppingData(): void
    {
        foreach (['exposures', 'conversions'] as $kind) {
            $sql = $this->read('disable_' . $kind . '_ttl.sql');

            Assert::string($sql)->contains('REMOVE TTL');
            Assert::false(str_contains($sql, 'DROP TABLE'));
        }
    }

    private function read(string $file): string
    {
        $contents = file_get_contents(self::RETENTION_DIR . '/' . $file);
        Assert::true($contents !== false);

        return $contents;
    }
}
