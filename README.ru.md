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

```php
use Rasuvaeff\Yii3Outbox\StorageInterface;
use Rasuvaeff\Yii3Outbox\OutboxMessage;

final class DbStorage implements StorageInterface
{
    public function save(OutboxMessage $message): void
    {
        // INSERT INTO outbox ... ON CONFLICT(id) DO UPDATE ...
    }

    public function findPending(array $types = [], int $limit = 1000): array
    {
        // SELECT * FROM outbox WHERE status = 'pending'
        //   [AND type IN (:types)] LIMIT :limit  -- empty $types = all types
        // For retry support, also return status = 'pending' with attempts > 0
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
// $result->failed   — publish exceptions (message kept Pending if retries remain)
// $result->skipped  — not yet ready for retry
```

### Поведение повторов

При сбое публикации:
- Если attempts < `maxAttempts` → сообщение остаётся `Pending`, будет повторено через `delaySeconds`
- Если attempts >= `maxAttempts` → сообщение помечается `Failed` (терминальный статус)

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
| `record(type, payload, aggregateId?, id?)` | Создаёт и сохраняет сообщение, возвращает `OutboxMessage` |

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

| Case | Значение |
|---|---|
| `Pending` | `'pending'` |
| `Published` | `'published'` |
| `Failed` | `'failed'` |

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
| `$failed` | Количество исключений публикации в этом запуске |
| `$skipped` | Количество сообщений, не готовых к повтору |
| `total()` | Сумма всех счётчиков |

### Serializer

| Метод | Описание |
|---|---|
| `serialize(message)` | Сообщение в JSON |
| `deserialize(data)` | JSON в сообщение |

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
