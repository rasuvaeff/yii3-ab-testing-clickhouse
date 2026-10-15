# rasuvaeff/yii3-ab-testing-clickhouse

[![Stable Version](https://img.shields.io/packagist/v/rasuvaeff/yii3-ab-testing-clickhouse.svg?label=stable)](https://packagist.org/packages/rasuvaeff/yii3-ab-testing-clickhouse)
[![Total Downloads](https://img.shields.io/packagist/dt/rasuvaeff/yii3-ab-testing-clickhouse.svg)](https://packagist.org/packages/rasuvaeff/yii3-ab-testing-clickhouse)
[![Build](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-ab-testing-clickhouse/build.yml?branch=master)](https://github.com/rasuvaeff/yii3-ab-testing-clickhouse/actions)
[![Static Analysis](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-ab-testing-clickhouse/static-analysis.yml?branch=master&label=static%20analysis)](https://github.com/rasuvaeff/yii3-ab-testing-clickhouse/actions)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/yii3-ab-testing-clickhouse/php)](https://packagist.org/packages/rasuvaeff/yii3-ab-testing-clickhouse)
[![License](https://img.shields.io/packagist/l/rasuvaeff/yii3-ab-testing-clickhouse.svg)](LICENSE.md)
[English version](README.md)

Аналитическая схема ClickHouse для A/B-тестирования в Yii3. Пакет владеет
таблицами схемы v2, в которые пишут оба пути доставки, поставляет их миграции и
даёт продюсерам контракт колонок для самопроверки. События он **не** пишет.

> Используете AI-ассистента? В [llms.txt](llms.txt) — компактный API-справочник,
> которым можно поделиться с моделью.
> Проекты с Composer-плагином [llm/skills](https://github.com/roxblnfk/skills)
> дополнительно получают agent skill этого пакета: он автоматически синкается в
> `.agents/skills/` при установке.

> **Сайт документации:** [rasuvaeff.github.io/yii3-ab-testing](https://rasuvaeff.github.io/yii3-ab-testing/)
> охватывает всё семейство, включая
> [матрицу интеграций](https://rasuvaeff.github.io/yii3-ab-testing/integration)
> для сборки своей комбинации (на английском).

## Требования

- PHP 8.3+
- `rasuvaeff/yii3-ab-testing` ^2.0
- `rasuvaeff/clickhouse-toolkit` ^1.6 и `simpod/clickhouse-client` ^0.8
  (с PSR-18 HTTP-клиентом, например `guzzlehttp/guzzle`) — чтобы применить схему

## Установка

```bash
composer require rasuvaeff/yii3-ab-testing-clickhouse
```

Обновляетесь с 1.x? См. [UPGRADE.md](UPGRADE.md) — прямых writer'ов больше нет.

## Как сюда попадают события

Трекера в пакете нет. События приходят одним из двух путей, и оба дают
одинаковые строки:

| Путь | Установить | Компромисс |
|---|---|---|
| Durable | `yii3-ab-testing-outbox` + `yii3-outbox-db` + воркер (`outbox:clickhouse:export`) | переживает недоступность аналитики; нужны таблица и работающий воркер |
| Log shipping | `LoggerExposureTracker` / `LoggerConversionTracker` из ядра + Vector / Fluent Bit | без воркера и без сетевого вызова в запросе; доставка — забота коллектора |

Никогда не вставляйте строки из пути запроса. Под PHP-FPM это синхронный INSERT
нескольких строк на каждый запрос внутри латентности посетителя — именно
поэтому прямой writer удалён в 2.0.

`config/di.php` ничего не биндит, поэтому установка рядом с любым
адаптером-трекером не может вызвать ошибку `Duplicate key` в `yiisoft/config`.

## Схема БД

| Таблица | Константа | Строки |
|---|---|---|
| `ab_exposures_v2` | `AnalyticsSchemaV2::EXPOSURES_TABLE` | по одной на событие показа |
| `ab_conversions_v2` | `AnalyticsSchemaV2::CONVERSIONS_TABLE` | по одной на конверсию, плюс `goal` и `exposure_event_id` |

```sql
CREATE TABLE ab_exposures_v2
(
    event_id            String,
    occurred_at         DateTime64(3, 'UTC'),
    ingested_at         DateTime('UTC') DEFAULT now(),
    experiment          LowCardinality(String),
    variant             LowCardinality(String),
    subject_id          String,
    decision_reason     LowCardinality(String),
    assignment_source   LowCardinality(String),
    experiment_revision LowCardinality(String) DEFAULT '',
    environment         LowCardinality(String) DEFAULT '',
    dimensions          String DEFAULT '{}'
)
ENGINE = ReplacingMergeTree(ingested_at)
PARTITION BY toYYYYMM(occurred_at)
ORDER BY (experiment, event_id)
```

- `event_id` выпускается ядром один раз и служит ключом дедупликации всего
  конвейера: повторная доставка после неопределённого исхода несёт то же
  значение.
- `occurred_at` — время события, а не доставки. От него считается ключ
  партиции, поэтому ретрай через границу месяца попадает в ту же партицию и
  схлопывается.
- `ingested_at` заполняет сама таблица; продюсер его **не** передаёт.
- `dimensions` — JSON-**строка**, а не `Map`: outbox-экспортёр отвергает
  вложенные поля payload, а оба пути доставки обязаны давать одинаковые строки.
  Читать через `JSONExtract(dimensions, 'Map(String, String)')`.

### Контракт колонок

```php
use Rasuvaeff\Yii3AbTestingClickHouse\AnalyticsSchemaV2;

AnalyticsSchemaV2::EXPOSURE_COLUMNS;   // колонки INSERT, по порядку
AnalyticsSchemaV2::CONVERSION_COLUMNS; // те же плюс goal и exposure_event_id
```

**Порядок** колонок — часть контракта: INSERT перечисляет колонки по позиции, и
продюсер, переставивший их, запишет вариант в колонку субъекта без всякой
ошибки. `yii3-ab-testing-outbox` маршрутизирует в эти константы, а не
дублирует их, а тест привязывает их к поставляемому DDL.

### Применение миграций

```php
use Rasuvaeff\Yii3AbTestingClickHouse\SchemaMigrations;

$applied = (new SchemaMigrations(client: $clickHouseClient, logger: $logger))->apply();
// список применённых миграций; повторный вызов вернёт []
```

`apply()` прогоняет поставляемые `migrations/*.sql` через
`ClickHouseMigrationRunner` из `clickhouse-toolkit`, находя их от самого пакета,
а не по захардкоженному пути в `vendor/`. Применение явное, никогда не
автоматическое: создание аналитических таблиц — шаг деплоя.

Свои имена таблиц — `apply('exp_v2', 'conv_v2')`. Приложение со своим
конвейером миграций (например, `rasuvaeff/yii3-clickhouse-toolkit`) берёт
каталог и точную карту плейсхолдеров у пакета:

```php
use Rasuvaeff\Yii3AbTestingClickHouse\SchemaMigrations;

SchemaMigrations::path();          // каталог поставляемых .sql
SchemaMigrations::placeholders();  // ['exposures_table' => …, 'exposures_table_v2' => …, …]
```

**Переименование после первого применения.** Чексумма runner'а покрывает
итоговый SQL, поэтому смена имени после применения миграции сообщается как
расхождение, а не создаёт молча вторую таблицу.

### Таблицы схемы v1

Таблицы v1 `ab_exposures` / `ab_conversions` по-прежнему создаются, чтобы
установка, обновлённая с 1.x, продолжала читать свою историю. В v2 они **не**
мигрируются и построчно несравнимы: у строк v1 нет идентичности события, а их
`ts` — время приёма.

### Retention

Opt-in TTL-шаблоны лежат в `retention/`. Это намеренно не миграции: установка
или обновление пакета никогда не должны начинать удалять аналитические данные.
Поставляемые шаблоны рассчитаны на таблицы **v1** (`{{exposures_table}}` /
`{{conversions_table}}`, колонка `ts`): подставьте их и положительное целое
`{{retention_days}}`, проверьте SQL и примените через свой процесс деплоя.
Парные шаблоны `disable_*_ttl.sql` снимают политику, не удаляя строки. Для
таблиц v2 напишите аналогичный `MODIFY TTL` по `occurred_at`.

## Чтение результатов

```sql
SELECT variant, uniqExact(subject_id) AS subjects
FROM ab_exposures_v2 FINAL
WHERE experiment = 'checkout_button'
  AND decision_reason = 'assigned'
GROUP BY variant
```

- **`FINAL`** (или `GROUP BY event_id` с `argMax`). `ReplacingMergeTree`
  схлопывает дубликаты только при слиянии, а доставка at-least-once, так что
  простой подсчёт после любого ретрая завышен. Правило зафиксировано в
  `AnalyticsSchemaV2::DEDUPLICATION_NOTE`.
- **`decision_reason = 'assigned'`**. Принудительный (QA) трафик и оба вида
  fallback записываются, но участием в эксперименте не являются.

## Справочник API

| Класс | Описание |
|---|---|
| `AnalyticsSchemaV2` | Имена таблиц и упорядоченные колонки INSERT схемы v2 — контракт для продюсеров, привязанный к поставляемому DDL |
| `SchemaMigrations` | Применяет поставляемые `.sql`; отдаёт их путь и карту плейсхолдеров |

## Безопасность

- Учётные данные передаются через конфигурацию клиента `clickhouse-toolkit`
  (заголовки / env), никогда не в URL.
- `subject_id` хранится как есть и может быть персональными данными. Настройте
  retention под свои обязательства по приватности.

## Примеры

В [examples/](examples/) — исполняемый скрипт (сервер не нужен), который печатает
контракт колонок и форму запроса для чтения.

## Разработка

```bash
composer build          # полный gate: validate + normalize + cs + psalm + test
composer cs:fix         # автоисправление стиля
composer psalm          # статический анализ
composer test           # unit-тесты (интеграционные пропускаются без CLICKHOUSE_HOST)
vendor/bin/testo --suite=Integration # нужен CLICKHOUSE_HOST; в CI гоняется вживую
```

## Лицензия

BSD-3-Clause. См. [LICENSE.md](LICENSE.md).
