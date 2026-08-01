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
> Проекты с Composer-плагином [llm/skills](https://github.com/roxblnfk/skills)
> дополнительно получают agent skill этого пакета: он автоматически синкается в
> `.agents/skills/` при установке.

## Требования

- PHP 8.3+
- `rasuvaeff/yii3-ab-testing` ^1.2
- `rasuvaeff/clickhouse-toolkit` ^1.6
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
`Rasuvaeff\ClickHouseToolkit\ClickHouseClientFactory` и
`Psr\Log\LoggerInterface`. Фабрика создаёт пакетных писателей, а logger получает
предупреждения о доставке. Зарегистрируйте обе зависимости в приложении (в Yii
logger обычно уже зарегистрирован):

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
`*.sql` и применяется через `ClickHouseMigrationRunner` из toolkit'а. Имена
таблиц — плейсхолдеры `{{exposures_table}}` / `{{conversions_table}}`, раннер
подставляет их до вычисления контрольной суммы и выполнения:

```php
use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationRunner;

(new ClickHouseMigrationRunner(
    client: $clickHouseClient,
    migrationsPath: __DIR__ . '/vendor/rasuvaeff/yii3-ab-testing-clickhouse/migrations',
    placeholders: [
        'exposures_table' => 'ab_exposures',
        'conversions_table' => 'ab_conversions',
    ],
))->run();
```

При проводке через `rasuvaeff/yii3-clickhouse-toolkit` (v1.1+) те же значения
приходят из params. Имя используется дважды — writer'ом и миграцией — поэтому
задавайте его один раз:

```php
// config/common/params.php
$exposures = 'ab_exposures';
$conversions = 'ab_conversions';

return [
    'rasuvaeff/yii3-ab-testing-clickhouse' => [
        'exposuresTable' => $exposures,
        'conversionsTable' => $conversions,
    ],
    'rasuvaeff/yii3-clickhouse-toolkit' => [
        'migrationPlaceholders' => [
            'exposures_table' => $exposures,
            'conversions_table' => $conversions,
        ],
    ],
];
```

Два блока params, а не один, потому что `yiisoft/config` разрешает определять
конкретный ключ ровно одному vendor-пакету: этот пакет не может дописать
`migrationPlaceholders` в конфигурацию toolkit'а без ошибки `Duplicate key`.

До v1.1 params переименовывали только **writer**, а поставляемый DDL всегда
создавал `ab_exposures` / `ab_conversions` — то есть настройка давала writer,
пишущий в таблицу, которую никто не создавал.

**Переименование после первого применения.** Контрольная сумма раннера считается
по подставленному SQL, поэтому смена имени после применения миграции
сообщается как расхождение, а не создаёт молча вторую таблицу. Создайте новую
таблицу сами (DDL лежит в `migrations/`) либо удалите строку этого файла из
таблицы `_migrations` и перезапустите.

| Таблица | Колонки |
|---|---|
| `ab_exposures` | `experiment, variant, subject_id, is_forced, is_fallback, is_sticky, environment, ts` |
| `ab_conversions` | `experiment, variant, subject_id, goal, is_forced, is_fallback, is_sticky, environment, ts` |

Обе таблицы — `MergeTree` с партицированием по `toYYYYMM(ts)`; `ts` по умолчанию
равно `now()`.

### Retention

Opt-in TTL-шаблоны для schema v1 лежат в `retention/`. Они намеренно не являются
миграциями: установка или обновление пакета не должны автоматически начинать
удаление аналитических данных. Подставьте `{{exposures_table}}` /
`{{conversions_table}}` и положительное целое `{{retention_days}}`, проверьте SQL
и примените его своим deployment-процессом. Парные `disable_*_ttl.sql` удаляют
политику, не удаляя уже сохранённые строки. Смена срока выполняется обычным
`MODIFY TTL`; ClickHouse применяет её асинхронно.

## Использование

```php
use Rasuvaeff\ClickHouseToolkit\ClickHouseBatchWriter;
use Rasuvaeff\Yii3AbTesting\AbTesting;
use Rasuvaeff\Yii3AbTestingClickHouse\ClickHouseConversionTracker;
use Rasuvaeff\Yii3AbTestingClickHouse\ClickHouseExposureTracker;

$exposure = new ClickHouseExposureTracker(
    writer: new ClickHouseBatchWriter($client, 'ab_exposures', ClickHouseExposureTracker::COLUMNS),
    logger: $logger,
);
$conversion = new ClickHouseConversionTracker(
    writer: new ClickHouseBatchWriter($client, 'ab_conversions', ClickHouseConversionTracker::COLUMNS),
    logger: $logger,
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

Строки накапливаются в in-memory буфере. При достижении `autoFlushSize` (по
умолчанию 1000) вызов `trackExposure()` или `trackConversion()` пытается
выполнить одну пакетную сетевую запись; в остальных случаях запись происходит
на `flush()`. Поэтому прямой ClickHouse-трекинг — best-effort приёмник, а не
надёжная очередь. Пакет содержит `ClickHouseTrackingFlushMiddleware` для
рекомендуемого сброса в конце запроса:

```php
use Rasuvaeff\Yii3AbTestingClickHouse\ClickHouseTrackingFlushMiddleware;

return [
    // Должно оборачивать все middleware/handler'ы, способные записать событие.
    ClickHouseTrackingFlushMiddleware::class,
];
```

Middleware оборачивает downstream-обработчик в `try/finally`, сбрасывает оба
трекера после запроса и проглатывает/логирует ошибки сброса, чтобы аналитика
никогда не ломала ответ пользователю. Зарегистрируйте его снаружи (раньше всех
таких обработчиков, если первый элемент pipeline является внешним) относительно
всех middleware и кода приложения, способных записать событие.

Неудачный auto-flush сохраняет события для повтора и пишет warning
`Failed to auto-flush ClickHouse A/B testing tracker`. Чтобы ограничить память
worker'а, буфер ограничен `10 * autoFlushSize`; при удалении старейших событий
пишется `Dropped ClickHouse A/B testing events after repeated flush failures` с
числом `droppedEvents`. Их структурированные значения `event` — `flush_failed`
и `dropped`. Отслеживайте оба warning. События, оставшиеся в буфере к моменту
завершения процесса, теряются.

Для метрик и трассировки реализуйте `TrackingObserverInterface` и привяжите его
в DI. Он сообщает `buffered`, `written`, `flushFailed` и `dropped` с типом
tracker'а и счётчиками событий. Метки должны быть низкокардинальными, observer не
должен бросать исключения. По умолчанию используется `NullTrackingObserver`;
PSR-3 warnings продолжают работать независимо.

Буфер пишет через внутренний `TrackingBatchSinkInterface`. Tracker'ы принимают
необязательный `secondarySink` для контролируемого dual-write; пакетный DI его
не настраивает, schema v2 не поставляется и не включается. Если secondary write
упал после успешного primary write, весь сохранённый batch будет повторён,
поэтому каждый target обязан выдерживать at-least-once delivery.

Если вы не используете PSR-15 pipeline, вызывайте `flush()` сами — один раз в
конце запроса или из `register_shutdown_function()`.

## API reference

| Класс | Описание |
|---|---|
| `ClickHouseExposureTracker` | Буферизует показы; `flush()` пакетно пишет в `ab_exposures` |
| `ClickHouseConversionTracker` | Буферизует конверсии (с `goal`); `flush()` пакетно пишет в `ab_conversions` |
| `ClickHouseTrackingFlushMiddleware` | PSR-15 middleware, безопасно сбрасывающее оба трекера в конце запроса |
| `TrackingObserverInterface` | Сигналы метрик/трассировки для buffered, written, failed и dropped событий |
| `AnalyticsSchemaV2` | Контракт колонок, с которым сверяются производители; закреплён за поставляемой DDL |
| `SchemaMigrations` | Применяет поставляемые `.sql` без хардкода пути в vendor |

## Безопасность

- Учётные данные подключения передаются через `ClickHouseClientFactory` из
  toolkit'а (заголовки / конфиг из env), а не в URL. Toolkit валидирует
  идентификаторы таблиц и колонок и использует параметризованные вставки.
- `subject_id` хранится как есть и может содержать персональные данные.
  Настройте TTL / партиционную политику удержания в соответствии с вашей
  privacy-политикой.
- Ошибки auto-flush и middleware flush намеренно проглатываются. Отслеживайте
  предупреждения об ошибках доставки и отброшенных событиях; если нужна
  гарантированная доставка, используйте outbox-адаптер.

## Примеры

См. [examples/](examples/) — запускаемый скрипт (сервер не требуется,
используется in-memory writer).

## Разработка

```bash
composer build          # full gate: validate + normalize + cs + psalm + test
composer cs:fix         # auto-fix code style
composer psalm          # static analysis
composer test           # run unit tests (integration tests skipped without CLICKHOUSE_HOST)
vendor/bin/testo --suite=Integration # требует CLICKHOUSE_HOST; в CI запускается с живым ClickHouse
```

## Лицензия

BSD-3-Clause. См. [LICENSE.md](LICENSE.md).
