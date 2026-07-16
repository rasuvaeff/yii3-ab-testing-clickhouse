# rasuvaeff/yii3-ab-testing-clickhouse
[![Stable Version](https://img.shields.io/packagist/v/rasuvaeff/yii3-ab-testing-clickhouse.svg?label=stable)](https://packagist.org/packages/rasuvaeff/yii3-ab-testing-clickhouse)
[![Total Downloads](https://img.shields.io/packagist/dt/rasuvaeff/yii3-ab-testing-clickhouse.svg)](https://packagist.org/packages/rasuvaeff/yii3-ab-testing-clickhouse)
[![Build](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-ab-testing-clickhouse/build.yml?branch=master)](https://github.com/rasuvaeff/yii3-ab-testing-clickhouse/actions)
[![Static Analysis](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-ab-testing-clickhouse/static-analysis.yml?branch=master&label=static%20analysis)](https://github.com/rasuvaeff/yii3-ab-testing-clickhouse/actions)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/yii3-ab-testing-clickhouse/php)](https://packagist.org/packages/rasuvaeff/yii3-ab-testing-clickhouse)
[![License](https://img.shields.io/packagist/l/rasuvaeff/yii3-ab-testing-clickhouse.svg)](LICENSE.md)
Трекеры воздействия и конверсий ClickHouse для A/B-тестирования Yii3. Реализует интерфейсы
 ExposureTracker и ConversionTracker из rasuvaeff/yii3-ab-testing,
 буферизующие события в памяти и записывающие их в ClickHouse в пакетном режиме.

 > Используете помощника по программированию с искусственным интеллектом? [llms.txt](llms.txt) содержит компактную ссылку на API, которую вы можете использовать в контексте приглашения. @@ЛИНИЯ@@
## Требования
- PHP 8.3+
 - `rasuvaeff/yii3-ab-testing` ^1.2
 - `rasuvaeff/clickhouse-toolkit` ^1.1
 - HTTP-клиент PSR-18 (например, `guzzlehttp/guzzle`) для подключения ClickHouse

## Установка
```bash
composer require rasuvaeff/yii3-ab-testing-clickhouse
```
С помощью плагина конфигурации Yii3 этот пакет автоматически связывает ExposureTracker, ConversionTracker
 и ClickHouseTrackingFlushMiddleware. Не привязывайте интерфейсы
 трекера от другого адаптера одновременно, иначе `yiisoft/config` сообщит об ошибке
 `Duplate key`. Чтобы отправлять события в несколько приемников, скомпонуйте их с помощью ядра
 CompositeExposureTracker или CompositeConversionTracker.

 Фабрика DI извлекает `Rasuvaeff\ClickHouseToolkit\ClickHouseClientFactory`
 из контейнера и использует его для создания пакетных средств записи. Привяжите фабрику в
 вашего приложения:

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
DDL для двух таблиц событий поставляется в папке `migrations/` как файлы ClickHouse `*.sql`
, применяемые с помощью `ClickHouseMigrationRunner` из набора инструментов:

```php
use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationRunner;

(new ClickHouseMigrationRunner(
    $clickHouseClient,
    __DIR__ . '/vendor/rasuvaeff/yii3-ab-testing-clickhouse/migrations',
))->run();
```
| Стол | Столбцы |
 |---|---|
 | `ab_exposures` | `эксперимент, вариант, subject_id, is_forced, is_fallback, is_sticky, среда, ts` |
 | `ab_conversions` | `эксперимент, вариант, subject_id, цель, is_forced, is_fallback, is_sticky, среда, ts` |

 Оба представляют собой `MergeTree`, разделенные `toYYYYMM(ts)`; `ts` по умолчанию имеет значение `now()`. @@ЛИНИЯ@@
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
Отслеживание никогда не вызывает сетевой вызов trackExposure() или trackConversion().
 Строки добавляются в буфер в памяти и записываются с помощью `flush()`. В пакет
 входит ClickHouseTrackingFlushMiddleware для рекомендуемой очистки в конце запроса:

```php
use Rasuvaeff\Yii3AbTestingClickHouse\ClickHouseTrackingFlushMiddleware;

return [
    ClickHouseTrackingFlushMiddleware::class,
    // place it late in the PSR-15 pipeline
];
```
Промежуточное программное обеспечение оборачивает нижестоящий обработчик в `try/finally`, очищает оба трекера
 после запроса и поглощает/регистрирует ошибки очистки, поэтому аналитика никогда
 не нарушает ответ пользователя.

 Если вы не используете конвейер PSR-15, вызовите `flush()` самостоятельно один раз в конце запроса
 или из `register_shutdown_function()`. @@ЛИНИЯ@@
## Справочник по API
| Класс | Описание |
 |---|---|
 | `ClickHouseExposureTracker` | Буферизирует риски; `flush()` выполняет пакетную запись в `ab_exposures` |
 | `ClickHouseConversionTracker` | Преобразования буферов (с «целью»); `flush()` выполняет пакетную запись в `ab_conversions` |
 | `ClickHouseTrackingFlushMiddleware` | Промежуточное программное обеспечение PSR-15, которое безопасно очищает оба трекера в конце запроса | @@ЛИНИЯ@@
## Безопасность
- Учетные данные для подключения передаются через ClickHouseClientFactory
 инструментария (заголовки/конфигурация из env), а не через URL-адреса. Инструментарий проверяет идентификаторы таблиц и столбцов
 и использует параметризованные вставки.
 — `subject_id` сохраняется дословно и может идентифицировать личность. Примените сохранение разделов TTL /
 в соответствии с вашей политикой конфиденциальности.
 — промежуточное программное обеспечение по своей конструкции игнорирует сбои очистки, поэтому добавьте ведение журнала/мониторинг для
 предупреждающего сообщения, если доставка аналитики имеет важное значение для эксплуатации. @@ЛИНИЯ@@
## Примеры
См. [examples/](examples/) для работоспособного сценария (сервер не требуется — используется записывающее устройство
 в памяти). @@ЛИНИЯ@@
## Разработка
```bash
composer build          # full gate: validate + normalize + cs + psalm + test
composer cs:fix         # auto-fix code style
composer psalm          # static analysis
composer test           # run unit tests (integration tests skipped without CLICKHOUSE_HOST)
```
## Лицензия
BSD-3-пункт. См. [LICENSE.md](LICENSE.md).
