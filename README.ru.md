# rasuvaeff/yii3-outbox

[![Stable Version](https://poser.pugx.org/rasuvaeff/yii3-outbox/v/stable)](https://packagist.org/packages/rasuvaeff/yii3-outbox)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/yii3-outbox/downloads)](https://packagist.org/packages/rasuvaeff/yii3-outbox)
[![Build](https://github.com/rasuvaeff/yii3-outbox/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/yii3-outbox/actions)
[![Static analysis](https://github.com/rasuvaeff/yii3-outbox/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/yii3-outbox/actions)
[![Psalm Level](https://shepherd.dev/github/rasuvaeff/yii3-outbox/level.svg)](https://shepherd.dev/github/rasuvaeff/yii3-outbox)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/yii3-outbox/php)](https://packagist.org/packages/rasuvaeff/yii3-outbox)
[![License](https://poser.pugx.org/rasuvaeff/yii3-outbox/license)](https://packagist.org/packages/rasuvaeff/yii3-outbox)
[English version](README.md)

Реализация паттерна transactional outbox для Yii3. Предоставляет stateless-ядро
для надёжной публикации сообщений с настраиваемыми политиками повторов.

> Используете AI-ассистента? В [llms.txt](llms.txt) — компактный API-справочник.
> Проекты с Composer-плагином [llm/skills](https://github.com/roxblnfk/skills) дополнительно получают agent-скилл этого пакета в `.agents/skills/` автоматически при установке.

## Требования

- PHP 8.3+
- `psr/clock` ^1.0
- `psr/log` ^3.0

## Установка

```bash
composer require rasuvaeff/yii3-outbox
```

## Использование

### Запись сообщения

```php
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Outbox\InMemoryStorage;
use Rasuvaeff\Yii3Outbox\Outbox;

$clock = new class implements ClockInterface {
    public function now(): DateTimeImmutable { return new DateTimeImmutable(); }
};

$outbox = new Outbox(storage: $storage, clock: $clock);

$message = $outbox->record(
    type: 'order.created',
    payload: json_encode(['orderId' => 42]),
    aggregateId: 'order-42',
);
```

### Транзакционная гарантия

Паттерн оправдывает своё название только тогда, когда запись в outbox
коммитится **атомарно с той бизнес-записью, которую она описывает**. Ядро не
может это обеспечить: оно не открывает транзакцию и ничего не знает о вашем
соединении. Отсюда две обязанности на стороне приложения:

1. Вызывать `record()` внутри той же транзакции БД, что и бизнес-запись.
2. Использовать хранилище, пишущее через то же соединение, что и бизнес-таблицы
   — `rasuvaeff/yii3-outbox-db` принимает `ConnectionInterface` именно ради
   этого.

```php
$db->transaction(static function () use ($orders, $outbox, $order, $json): void {
    $orders->insert($order);

    $outbox->record(
        type: 'order.created',
        payload: $json,
        aggregateId: $order->id,
    );
});
```

Нарушьте любую из двух — и гарантии нет: закоммитили заказ без сообщения, и
событие потеряно навсегда; закоммитили сообщение без заказа, и потребители
увидят событие, которого не было. Хранилище поверх другой БД (или поверх
брокера сообщений) такую гарантию не даёт в принципе, а `InMemoryStorage` —
тестовый дубль, а не долговечное хранилище.

### Идентификаторы сообщений

Id сообщения — это первичный ключ таблицы outbox, а при экспорте сообщений в
ClickHouse ещё и ключ дедупликации в `ReplacingMergeTree`. Управлять им можно
двумя способами.

**Передать id доменного события** — правильный выбор всегда, когда сообщение
отражает событие, у которого идентификатор уже есть. Повторная публикация того
же события тогда не порождает второй id, и потребителю есть по чему
дедуплицировать:

```php
$outbox->record(
    type: 'order.created',
    payload: $json,
    aggregateId: 'order-42',
    id: $domainEvent->getId(),
);
```

**Забиндить генератор** — для сообщений, у которых доменного id нет.
`RandomHexIdGenerator` по умолчанию сохраняет исторический формат (32
случайных hex-символа); упорядоченный по времени id заставляет вставки идти в
конец, а не разбрасываться по страницам InnoDB, и даёт стабильный порядок
выборки пачек:

```php
use Rasuvaeff\Yii3Outbox\MessageIdGeneratorInterface;

// symfony/uid
final readonly class Uuid7IdGenerator implements MessageIdGeneratorInterface
{
    public function generate(): string
    {
        return \Symfony\Component\Uid\Uuid::v7()->toRfc4122();
    }
}

// ramsey/uuid — так же монотонен внутри одной миллисекунды
final readonly class RamseyUuid7IdGenerator implements MessageIdGeneratorInterface
{
    public function generate(): string
    {
        return \Ramsey\Uuid\Uuid::uuid7()->toString();
    }
}

$outbox = new Outbox(storage: $storage, clock: $clock, idGenerator: new Uuid7IdGenerator());
```

Пакет не поставляет реализацию UUID и не зависит ни от одной UUID-библиотеки:
`id` в `rasuvaeff/yii3-outbox-db` — `VARCHAR(255)`, поэтому влезает любой
формат, а выбор остаётся за вами.

### Реализация хранилища

`claim()` — примитив, на котором держится весь цикл поллинга: `Processor`
вызывает именно его, а не `findPending()`. Он обязан атомарно переводить
сообщения в `Processing` и возвращать их, чтобы два воркера, опрашивающие одну
таблицу, никогда не получили одно и то же сообщение. `findPending()` — его
read-only двойник: годится для дашбордов и диагностики, не годится как выборка
воркера.

```php
use Rasuvaeff\Yii3Outbox\StorageInterface;
use Rasuvaeff\Yii3Outbox\OutboxMessage;

final class DbStorage implements StorageInterface
{
    public function save(OutboxMessage $message): void
    {
        // INSERT INTO outbox ... ON CONFLICT(id) DO UPDATE ...
        // Должно идти по соединению вызывающего, чтобы коммититься с бизнес-записью.
    }

    public function claim(array $types = [], int $limit = 1000): array
    {
        // Атомарно: SELECT id со status = 'pending' [AND type IN (:types)]
        //   LIMIT :limit FOR UPDATE SKIP LOCKED
        // затем UPDATE outbox SET status = 'processing', claimed_by = :worker
        //   WHERE id IN (...) — и вернуть захваченные строки.
        // Каждое захваченное сообщение обязано закончить markPublished(),
        // markFailed() или save($msg->withStatus(Pending)) — ни одно не должно
        // остаться в Processing.
    }

    public function findPending(array $types = [], int $limit = 1000): array
    {
        // SELECT * FROM outbox WHERE status = 'pending'
        //   [AND type IN (:types)] LIMIT :limit  -- пустой $types = все типы
        // Read-only: атомарности нет, два воркера получат одни и те же строки.
        // Для поддержки повторов возвращать и status = 'pending' с attempts > 0
    }

    public function markPublished(OutboxMessage $message): void
    {
        // UPDATE outbox SET status = 'published' WHERE id = ?
    }

    public function markFailed(OutboxMessage $message): void
    {
        // UPDATE outbox SET status = 'failed' WHERE id = ?
    }

    public function getById(string $id): ?OutboxMessage
    {
        // SELECT * FROM outbox WHERE id = ?
    }
}
```

### Реализация паблишера

```php
use Rasuvaeff\Yii3Outbox\PublisherInterface;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\PublishException;

final class RabbitPublisher implements PublisherInterface
{
    public function publish(OutboxMessage $message): void
    {
        try {
            // publish to RabbitMQ, Kafka, etc.
        } catch (\Throwable $e) {
            throw new PublishException(
                message: $e->getMessage(),
                outboxMessage: $message,
                previous: $e,
            );
        }
    }
}
```

### Обработка outbox

```php
use Rasuvaeff\Yii3Outbox\Processor;
use Rasuvaeff\Yii3Outbox\RetryPolicy;

$processor = new Processor(
    storage: $storage,
    publisher: $publisher,
    retryPolicy: new RetryPolicy(maxAttempts: 3, delaySeconds: 60),
    clock: $clock,
    batchSize: 100,
);

$result = $processor->process();
// $result->published — successfully published
// $result->failed   — сбои публикации и сообщения с исчерпанными попытками
// $result->skipped  — ещё не готовы к повтору (задержка не истекла)
```

### Поведение повторов

При сбое публикации:
- Если attempts < `maxAttempts` → сообщение остаётся `Pending`, будет повторено через `delaySeconds`
- Если attempts >= `maxAttempts` → сообщение помечается `Failed` (терминальный статус)

Каждое заклеймленное батчем сообщение покидает `Processing`. Сообщение, которое
пришло в claim с уже исчерпанными попытками — восстановленное из бэкапа или
оставшееся после `markFailed()`, не доехавшего до базы, — сразу помечается
`Failed`, а не сохраняется обратно как `Pending`: иначе оно вечно ходило бы по
кругу claim → skip → save, и алерт по `Failed` никогда бы не сработал.

Паблишер, бросивший что-то кроме `PublishException`, — это баг, и `process()`
пробрасывает исключение дальше, а не молча ретраит его как проблему доставки.
Перед этим текущее сообщение сохраняется по политике повторов, а все сообщения,
которые батч успел заклеймить, но не успел обработать, освобождаются обратно в
`Pending` (или помечаются `Failed`, если попытки исчерпаны и у них), — в
`Processing` не остаётся ничего, что потом придётся доставать руками через SQL.

То же самое, когда падает не паблишер, а storage. Если `markPublished()` бросил
после успешного `publish()`, сообщение до потребителя дошло, а запись об этом —
нет: оно возвращается в `Pending`, и следующий прогон опубликует его снова.
**Пакет доставляет минимум один раз**, а ключ дедупликации на стороне
потребителя — id сообщения; оставить строку в `Processing` означало бы оставить
строку, которую этот API сдвинуть не может. Эта ветка логирует
`Outbox message was published but could not be marked published`, а не
предупреждение про паблишер: во время инцидента со storage дежурного не нужно
отправлять дебажить паблишер.

Освобождение батча по своей природе best-effort: оно обращается к тому же
storage, который, вполне возможно, и уронил батч, а лежащему storage ничего не
сообщить. Что оно гарантирует — так это что его собственные сбои логируются
(`Failed to release a claimed outbox message`), а не бросаются: пойманное
исключение всегда то, которое уронило батч, а не симптом, возникший при реакции
на него.

```php
$policy = new RetryPolicy(maxAttempts: 3, delaySeconds: 60);

$policy->shouldRetry($message);          // bool — attempts remaining?
$policy->isReadyForRetry($message, $now); // bool — delay elapsed?
```

### Использование InMemoryStorage в тестах

```php
use Rasuvaeff\Yii3Outbox\InMemoryStorage;

$storage = new InMemoryStorage();
$storage->save($message);

$pending = $storage->findPending();
$storage->count();
$storage->clear();
```

## Справочник по API

### Outbox

| Метод | Описание |
|---|---|
| `__construct(storage, clock, idGenerator?)` | Основная точка входа |
| `record(type, payload, aggregateId?, id?)` | Создаёт и сохраняет сообщение, возвращает `OutboxMessage`. Вызывать внутри бизнес-транзакции |

### StorageInterface

| Метод | Описание |
|---|---|
| `save(message)` | Сохранение. Должно коммититься с бизнес-записью — см. [Транзакционная гарантия](#транзакционная-гарантия) |
| `claim(types = [], limit = 1000)` | **Атомарно** переводит до `limit` сообщений из `Pending` в `Processing` и возвращает их. То, что использует `Processor`; безопасно для конкурентных воркеров |
| `findPending(types = [], limit = 1000)` | Read-only список `Pending`-сообщений. Атомарности нет — для дашбордов, не для воркеров |
| `markPublished(message)` | Терминальный успех |
| `markFailed(message)` | Терминальная неудача |
| `getById(id)` | `?OutboxMessage` |

`types` фильтрует по типу сообщения (пустой = все) — так несколько потребителей
делят один outbox. Поскольку `claim()` отдаёт сообщение ровно одному
вызывающему, наборы типов независимых потребителей не должны пересекаться,
иначе сообщение дойдёт только до того воркера, который захватил его первым.

### OutboxMessage

| Метод | Описание |
|---|---|
| `create(type, payload, aggregateId?, createdAt?, id?)` | Фабрика с авто-генерируемым ID |
| `getId()` | ID сообщения (32-символьный hex) |
| `getType()` | Тип сообщения |
| `getPayload()` | Сырая строка payload |
| `getStatus()` | Enum `OutboxStatus` |
| `getCreatedAt()` | `DateTimeImmutable` |
| `getAttempts()` | Количество попыток публикации |
| `getLastAttemptAt()` | `?DateTimeImmutable` |
| `getAggregateId()` | `?string` |
| `withStatus(status)` | Возвращает новый экземпляр со статусом |
| `withAttempt(at)` | Возвращает новый экземпляр с инкрементированными attempts и timestamp |

### MessageIdGeneratorInterface

| Реализация | Что выдаёт |
|---|---|
| `RandomHexIdGenerator` (по умолчанию) | 32 hex-символа, 128 случайных бит |
| собственная | что угодно непустое; `id` в DB-адаптере — `VARCHAR(255)` |

### OutboxStatus

| Case | Значение | Смысл |
|---|---|---|
| `Pending` | `'pending'` | Ожидает публикации, включая повторы с `attempts > 0` |
| `Processing` | `'processing'` | Захвачено воркером; другой воркер его не возьмёт |
| `Published` | `'published'` | Терминальный успех |
| `Failed` | `'failed'` | Терминальная неудача, повторы исчерпаны |

### RetryPolicy

| Метод | Описание |
|---|---|
| `__construct(maxAttempts, delaySeconds)` | По умолчанию: 3 попытки, задержка 60 с |
| `shouldRetry(message)` | Проверяет количество попыток |
| `isReadyForRetry(message, now)` | Проверяет попытки + истечение задержки |

### Processor

| Метод | Описание |
|---|---|
| `__construct(storage, publisher, retryPolicy, clock, batchSize, logger)` | Batch по умолчанию: 100 |
| `process()` | Возвращает `ProcessingResult` |

### ProcessingResult

| Свойство/Метод | Описание |
|---|---|
| `$published` | Количество успешно опубликованных сообщений |
| `$failed` | Количество сбоев публикации в этом запуске плюс сообщения, заклеймленные с исчерпанными попытками |
| `$skipped` | Количество сообщений, не готовых к повтору |
| `total()` | Сумма всех счётчиков |

### Serializer

`Serializer` реализует `SerializerInterface` — точку расширения, через которую
storage-backend или транспорт переносит сообщение через границу в виде строки.
Подставьте свою реализацию для другого формата; интерфейс — это контракт,
`Serializer` — JSON по умолчанию.

| Метод | Описание |
|---|---|
| `serialize(message)` | Сообщение в JSON |
| `deserialize(data)` | JSON в сообщение |

`deserialize()` отвергает любой некорректный вход через
`InvalidArgumentException` — отсутствующие поля, неверные типы, неизвестный
статус, непарсящуюся или пустую дату. Вызывающему, который ловит «плохой вход»,
не нужно дополнительно ловить `ValueError` или `DateMalformedStringException` от
поля, которое парсер забыл проверить.

## Безопасность

- Реализации хранилища должны использовать параметризованные запросы для всех пользовательских значений.
- Payload сообщения сохраняется как есть; при необходимости валидируйте перед сохранением.

## Примеры

Полные примеры использования — в [examples/](examples/).

## Разработка

```bash
make install
make build
make cs-fix
make test
make test-coverage
make mutation
make release-check
```

`make test-coverage` и `make mutation` поднимают `pcov` внутри контейнера
`composer:2`, потому что в базовом образе нет драйвера покрытия.

## Лицензия

BSD-3-Clause. См. [LICENSE.md](LICENSE.md).
