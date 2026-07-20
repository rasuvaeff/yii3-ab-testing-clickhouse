# rasuvaeff/yii3-ab-testing-clickhouse

[![Stable Version](https://img.shields.io/packagist/v/rasuvaeff/yii3-ab-testing-clickhouse.svg?label=stable)](https://packagist.org/packages/rasuvaeff/yii3-ab-testing-clickhouse)
[![Total Downloads](https://img.shields.io/packagist/dt/rasuvaeff/yii3-ab-testing-clickhouse.svg)](https://packagist.org/packages/rasuvaeff/yii3-ab-testing-clickhouse)
[![Build](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-ab-testing-clickhouse/build.yml?branch=master)](https://github.com/rasuvaeff/yii3-ab-testing-clickhouse/actions)
[![Static Analysis](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-ab-testing-clickhouse/static-analysis.yml?branch=master&label=static%20analysis)](https://github.com/rasuvaeff/yii3-ab-testing-clickhouse/actions)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/yii3-ab-testing-clickhouse/php)](https://packagist.org/packages/rasuvaeff/yii3-ab-testing-clickhouse)
[![License](https://img.shields.io/packagist/l/rasuvaeff/yii3-ab-testing-clickhouse.svg)](LICENSE.md)
[English version](README.md)

Трекеры показов и конверсий в ClickHouse для A/B-тестирования в Yii3. Реализует
интерфейсы `ExposureTracker` и `ConversionTracker` из `rasuvaeff/yii3-ab-testing`:
события буферизуются в памяти и записываются в ClickHouse пакетами.

> Используете AI-ассистента? В [llms.txt](llms.txt) — компактный API-справочник,
> которым можно поделиться с моделью.

## Требования

- PHP 8.3+
- `rasuvaeff/yii3-ab-testing` ^1.2
- `rasuvaeff/clickhouse-toolkit` ^1.1
- PSR-18 HTTP-клиент (например `guzzlehttp/guzzle`) для подключения к ClickHouse

## Установка

```bash
composer require rasuvaeff/yii3-ab-testing-clickhouse
```

С config-plugin из Yii3 пакет автоматически биндит `ExposureTracker`,
`ConversionTracker` и `ClickHouseTrackingFlushMiddleware`. Не биндите интерфейсы
трекеров из другого адаптера одновременно — иначе `yiisoft/config` сообщит об
ошибке `Duplicate key`. Чтобы отправлять события в несколько приёмников,
скомпонуйте их через ядерные `CompositeExposureTracker` /
`CompositeConversionTracker`.

DI-фабрика достаёт из контейнера
`Rasuvaeff\ClickHouseToolkit\ClickHouseClientFactory` и использует его для
создания пакетных писателей. Зарегистрируйте фабрику в приложении:

```php
use Rasuvaeff\ClickHouseToolkit\ClickHouseClientFactory;
use Rasuvaeff\ClickHouseToolkit\ClickHouseConfig;

return [
    ClickHouseClientFactory::class => static fn (): ClickHouseClientFactory => new ClickHouseClientFactory(
        new ClickHouseConfig(host: getenv('CLICKHOUSE_HOST') ?: '127.0.0.1', /* ... */),
    ),
];
```

## Схема базы данных

DDL для двух таблиц событий поставляется под `migrations/` как ClickHouse-файлы
`*.sql` и применяется через `ClickHouseMigrationRunner` из toolkit'а:

```php
use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationRunner;

(new ClickHouseMigrationRunner(
    $clickHouseClient,
    __DIR__ . '/vendor/rasuvaeff/yii3-ab-testing-clickhouse/migrations',
))->run();
```

| Таблица | Колонки |
|---|---|
| `ab_exposures` | `experiment, variant, subject_id, is_forced, is_fallback, is_sticky, environment, ts` |
| `ab_conversions` | `experiment, variant, subject_id, goal, is_forced, is_fallback, is_sticky, environment, ts` |

Обе таблицы — `MergeTree` с партицированием по `toYYYYMM(ts)`; `ts` по умолчанию
равно `now()`.

## Использование

```php
use Rasuvaeff\ClickHouseToolkit\ClickHouseBatchWriter;
use Rasuvaeff\Yii3AbTesting\AbTesting;
use Rasuvaeff\Yii3AbTestingClickHouse\ClickHouseConversionTracker;
use Rasuvaeff\Yii3AbTestingClickHouse\ClickHouseExposureTracker;

$exposure = new ClickHouseExposureTracker(
    writer: new ClickHouseBatchWriter($client, 'ab_exposures', ClickHouseExposureTracker::COLUMNS),
);
$conversion = new ClickHouseConversionTracker(
    writer: new ClickHouseBatchWriter($client, 'ab_conversions', ClickHouseConversionTracker::COLUMNS),
);

$ab = new AbTesting(
    provider: $provider,
    strategy: $strategy,
    exposureTracker: $exposure,
    conversionTracker: $conversion,
);

$assignment = $ab->assign(experiment: 'checkout-button', subjectId: (string) $userId);
$ab->trackExposure($assignment);            // buffered, not sent yet
$ab->trackConversion($assignment, goal: 'purchase');
```

### Сброс в конце запроса

Трекинг никогда не делает сетевого вызова в `trackExposure()` или
`trackConversion()`. Строки накапливаются в in-memory буфере и записываются на
`flush()`. Пакет содержит `ClickHouseTrackingFlushMiddleware` для рекомендуемого
сброса в конце запроса:

```php
use Rasuvaeff\Yii3AbTestingClickHouse\ClickHouseTrackingFlushMiddleware;

return [
    ClickHouseTrackingFlushMiddleware::class,
    // place it late in the PSR-15 pipeline
];
```

Middleware оборачивает downstream-обработчик в `try/finally`, сбрасывает оба
трекера после запроса и проглатывает/логирует ошибки сброса, чтобы аналитика
никогда не ломала ответ пользователю.

Если вы не используете PSR-15 pipeline, вызывайте `flush()` сами — один раз в
конце запроса или из `register_shutdown_function()`.

## API reference

| Класс | Описание |
|---|---|
| `ClickHouseExposureTracker` | Буферизует показы; `flush()` пакетно пишет в `ab_exposures` |
| `ClickHouseConversionTracker` | Буферизует конверсии (с `goal`); `flush()` пакетно пишет в `ab_conversions` |
| `ClickHouseTrackingFlushMiddleware` | PSR-15 middleware, безопасно сбрасывающее оба трекера в конце запроса |

## Безопасность

- Учётные данные подключения передаются через `ClickHouseClientFactory` из
  toolkit'а (заголовки / конфиг из env), а не в URL. Toolkit валидирует
  идентификаторы таблиц и колонок и использует параметризованные вставки.
- `subject_id` хранится как есть и может содержать персональные данные.
  Настройте TTL / партиционную политику удержания в соответствии с вашей
  privacy-политикой.
- Middleware намеренно проглатывает ошибки сброса — добавьте логирование /
  мониторинг для warning-сообщения, если доставка аналитики критична
  операционно.

## Примеры

См. [examples/](examples/) — запускаемый скрипт (сервер не требуется,
используется in-memory writer).

## Разработка

```bash
composer build          # full gate: validate + normalize + cs + psalm + test
composer cs:fix         # auto-fix code style
composer psalm          # static analysis
composer test           # run unit tests (integration tests skipped without CLICKHOUSE_HOST)
```

## Лицензия

BSD-3-Clause. См. [LICENSE.md](LICENSE.md).
