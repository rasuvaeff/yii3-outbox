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

$storage = new InMemoryStorage(); // для реальной базы — rasuvaeff/yii3-outbox-db
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

### Запись нескольких сообщений сразу

Запрос, породивший пять событий, записывает пять сообщений. `recordMany()`
принимает черновики — всё, что принимает `record()`, минус то, что outbox
заполняет сам, — читает часы один раз, чтобы батч был одним моментом в
истории outbox, и пишет их одним запросом, если хранилище —
`BatchSavingStorageInterface` (см. [Сохранение батча одной записью](#сохранение-батча-одной-записью)),
иначе — по одному `save()` на сообщение. Транзакционное обязательство то же,
что у `record()`:

```php
use Rasuvaeff\Yii3Outbox\OutboxMessageDraft;

$messages = $outbox->recordMany([
    new OutboxMessageDraft(type: 'order.created', payload: $created, aggregateId: 'order-42'),
    new OutboxMessageDraft(type: 'order.paid', payload: $paid, aggregateId: 'order-42', id: $paidEvent->getId()),
]);
// list<OutboxMessage> в заданном порядке; пустой список ничего не трогает
```

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

### Передача политики повторов в хранилище

`claim()` возвращает любые `Pending`-сообщения, поэтому `Processor` получает и
те, что ещё ждут окончания backoff, и каждое из них тут же пишет обратно как
`Pending`. Это две записи на каждое отложенное сообщение за итерацию, и каждое
занимает слот в `batchSize`, который мог бы достаться готовому к отправке
сообщению: при большой очереди повторов свежие сообщения ждут за ней.

Хранилище, способное выразить предикат на своём языке запросов, реализует
`RetryAwareStorageInterface` — `Processor` начинает захватывать через него
автоматически:

```php
use Rasuvaeff\Yii3Outbox\RetryAwareStorageInterface;

final class DbStorage implements RetryAwareStorageInterface
{
    public function claimReady(
        DateTimeImmutable $readyThreshold,
        int $maxAttempts,
        array $types = [],
        int $limit = 1000,
    ): array {
        // Тот же атомарный захват, что и claim(), плюс одно условие:
        //   AND (attempts >= :maxAttempts
        //        OR last_attempt_at IS NULL
        //        OR last_attempt_at <= :readyThreshold)
    }

    // ... остальной StorageInterface без изменений
}
```

Условие `attempts >= :maxAttempts` — не оптимизация, и выбрасывать его нельзя.
Сообщение, исчерпавшее попытки, повторить уже нельзя, и единственное, что с ним
осталось сделать, — пометить `Failed`, а `Processor` может сделать это только с
тем сообщением, которое хранилище ему отдало. Отфильтруйте его — и завершить
его не сможет никто: оно навсегда останется `Pending`, невидимое для алерта на
`Failed`.

`$readyThreshold` приходит из `RetryPolicy::readyThreshold($now)` — задержка
остаётся делом ядра, и реализация не должна её восстанавливать. Интерфейс
отдельный от `StorageInterface` потому, что добавление параметра в сам `claim()`
сломало бы любую стороннюю реализацию.

`rasuvaeff/yii3-outbox-db` его реализует. Хранилище без него по-прежнему
корректно: `Processor` откатывается на `claim()` и фильтрует в PHP, ровно как
раньше.

Два следствия, о которых стоит знать до того, как настроите на них алерты:

- `ProcessingResult::$skipped` считает сообщения, которые батч захватил и
  отбросил. С retry-aware хранилищем таких нет, поэтому там всегда `0` — работа,
  которую он считал, и есть то, что убирает этот интерфейс.
- Сообщение с исчерпанными попытками завершается на величину до `delaySeconds`
  позже, чем раньше: теперь оно ждёт батча, в который попадёт.

### Подтверждение батча одной записью

`markPublished()` принимает одно сообщение. Потребитель, доставляющий батч
целиком — одним bulk insert в ClickHouse, одним multi-message вызовом брокера, —
подтверждает его по одной записи на сообщение, а над SQL-хранилищем это тысяча
statements на батч из тысячи сообщений, и все они ложатся в ту же OLTP-базу,
куда приложение пишет бизнес-строки.

Хранилище, умеющее подтвердить много сообщений одним statement'ом, реализует
`BatchAcknowledgingStorageInterface`, а батчевый потребитель обнаруживает его
через `instanceof`:

```php
use Rasuvaeff\Yii3Outbox\BatchAcknowledgingStorageInterface;

final class DbStorage implements BatchAcknowledgingStorageInterface
{
    public function markPublishedBatch(array $messages): void
    {
        // UPDATE outbox SET status = 'published', ... WHERE id IN (:ids)
    }

    // ... остальной StorageInterface без изменений
}
```

Оставлять подтверждённую строку как `Published` или удалять её совсем — решение
хранилища, а не потребителя, и оно обязано распространяться и на
`markPublished()`: тогда все источники подтверждений сходятся в том, что лежит
в таблице. `rasuvaeff/yii3-outbox-db` реализует интерфейс и даёт оба поведения
за флагом; `rasuvaeff/yii3-outbox-clickhouse` подтверждает через него.
`Processor` публикует по одному сообщению и продолжает пользоваться
`markPublished()`: батчевое подтверждение расширило бы окно, в котором падение
переотправляет сообщения, уже отданные паблишеру. Хранилище без интерфейса
по-прежнему корректно — батчевый потребитель откатывается на
`markPublished()` по одному.

### Сохранение батча одной записью

Зеркальное отражение на стороне записи. `recordMany()` вызывает `save()` на
каждое сообщение; хранилище, способное выразить батч одним multi-row insert,
реализует `BatchSavingStorageInterface`, и `recordMany()` им пользуется:

```php
use Rasuvaeff\Yii3Outbox\BatchSavingStorageInterface;

final class DbStorage implements BatchSavingStorageInterface
{
    public function saveBatch(array $messages): void
    {
        // INSERT INTO outbox (...) VALUES (...), (...), (...)
    }
}
```

Транзакционный контракт — тот же, что у `save()`: через соединение
приложения, внутри транзакции вызывающего. Пустой список — no-op.

### Повторная постановка сбойных сообщений

Сообщение попадает в `Failed`, когда исчерпаны попытки или паблишер объявил
сбой терминальным. Когда причина устранена — получатель вернулся, баг в
payload выкачен, — оператор хочет всё-таки опубликовать эти сообщения, а
ничто в `StorageInterface` не умеет сдвинуть строку `Failed`. Хранилище,
которое умеет, реализует `RequeueableStorageInterface`; `Outbox::requeueFailed()`
им управляет:

```php
$moved = $outbox->requeueFailed(types: ['order.created'], limit: 500);
// каждое Failed-сообщение этого типа снова Pending с обнулёнными попытками;
// хранилище без этой возможности даёт LogicException
```

`requeue()` двигает только сообщение, которое хранилище *сейчас* держит как
`Failed`, — то, до которого раньше добрался воркер или другой оператор,
остаётся как есть и не считается. Интерфейс реализуют `InMemoryStorage` и
`rasuvaeff/yii3-outbox-db`.

### Наблюдение за очередью

Только растущий `Processing` означает, что воркеры умирают посреди батча;
только растущий `Failed` — что сломан паблишер. Хранилище, которое умеет
дёшево считать, реализует `StatsAwareStorageInterface` и отвечает одним
агрегирующим запросом:

```php
use Rasuvaeff\Yii3Outbox\StatsAwareStorageInterface;

if ($storage instanceof StatsAwareStorageInterface) {
    $stats = $storage->stats();
    $stats->pending;                          // int
    $stats->processing;                       // int
    $stats->failed;                           // int
    $stats->published;                        // int
    $stats->total();                          // сумма
    $stats->countOf(OutboxStatus::Failed);    // по case enum
    $stats->oldestPendingCreatedAt;           // ?DateTimeImmutable
    $stats->oldestPendingAgeSeconds($now);    // ?int — метрика для алерта
}
```

Снимок — gauge, а не журнал: два вызова вокруг конкурентной записи могут
разойтись, для health-check это нормально.

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

Любой `PublishException` повторяется, пока `RetryPolicy` не исчерпает
попытки. Когда паблишер знает, что повтор ничего не исправит — получатель
исчез (410), payload отвергнут как невалидный, — он говорит об этом, и
`Processor` сразу помечает сообщение `Failed`, вместо того чтобы тратить
оставшиеся попытки на откладывание алерта:

```php
throw PublishException::terminal(
    message: sprintf('Endpoint %s returned 410 Gone', $endpoint),
    outboxMessage: $message,
);
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
// $result->skipped  — захвачены, но не готовы к повтору; всегда 0 с
//                     RetryAwareStorageInterface, который их не захватывает
```

### Общее хранилище для нескольких потребителей

`Processor` забирает **все** ожидающие сообщения хранилища независимо от
типа. На хранилище, которое делит с другим потребителем, забирающим по типу —
`ClickHouseOutboxExporter` из `rasuvaeff/yii3-outbox-clickhouse`, второй
`Processor` с другим паблишером, — это потеря данных: нескоупленный процессор
забирает чужие сообщения, его паблишер делает с незнакомым типом что умеет
(вебхук-паблишер без эндпоинтов молча подтверждает), и сообщение оказывается
`Published` раньше, чем его увидит потребитель, для которого оно предназначено.

Ограничивайте каждый процессор своими типами:

```php
$webhooks = new Processor(
    storage: $storage,
    publisher: $webhookPublisher,
    retryPolicy: $policy,
    clock: $clock,
    types: ['order.created', 'order.paid'],
);

$broker = new Processor(
    storage: $storage,
    publisher: $rabbitPublisher,
    retryPolicy: $policy,
    clock: $clock,
    types: ['inventory.reserved'],
);
```

Скоуп пробрасывается в `claim()` / `claimReady()`, так что ограниченный
процессор чужое сообщение даже не видит. Пустой скоуп (по умолчанию)
по-прежнему забирает всё — правильно для типичного случая «одно хранилище,
один потребитель».

### Поведение повторов

При сбое публикации:
- Если attempts < `maxAttempts` → сообщение остаётся `Pending`, будет повторено через `delaySeconds`
- Если attempts >= `maxAttempts` → сообщение помечается `Failed` (терминальный статус)
- Если паблишер бросил `PublishException::terminal()` → сообщение сразу
  помечается `Failed` независимо от числа попыток; в warning-лог попадает
  `terminal: true`

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

$policy->shouldRetry($message);           // bool — attempts remaining?
$policy->isReadyForRetry($message, $now); // bool — delay elapsed?
$policy->readyThreshold($now);            // DateTimeImmutable — тот же вопрос
                                          // как граница для фильтра в хранилище
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
| `recordMany(list<OutboxMessageDraft>)` | То же для нескольких сообщений: одно чтение часов, один `saveBatch()`, если хранилище — `BatchSavingStorageInterface`, иначе по одному `save()`. Возвращает `list<OutboxMessage>` в заданном порядке; `[]` ничего не трогает |
| `requeueFailed(types = [], limit = 1000)` | Возвращает `Failed`-сообщения в `Pending` с обнулёнными попытками через `RequeueableStorageInterface`; возвращает число сдвинутых. `LogicException`, если хранилище не умеет |

### OutboxMessageDraft

| Свойство | Описание |
|---|---|
| `type`, `payload`, `aggregateId?`, `id?` | То, что принимает `record()`; `type` и `id` не могут быть пустыми. Потребляется `recordMany()` |

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

### RetryAwareStorageInterface

Расширяет `StorageInterface`. Опционален: реализуется, когда бэкенд умеет
применять политику повторов прямо внутри захвата — см.
[Передача политики повторов в хранилище](#передача-политики-повторов-в-хранилище).

| Метод | Описание |
|---|---|
| `claimReady(readyThreshold, maxAttempts, types = [], limit = 1000)` | Как `claim()`, но пропускает сообщения, ещё ждущие следующей попытки. Берёт сообщение, если оно ни разу не отправлялось, последняя попытка была в `readyThreshold` или раньше, либо попытки уже исчерпаны (`maxAttempts`) |

### BatchAcknowledgingStorageInterface

Расширяет `StorageInterface`. Опционален: реализуется, когда бэкенд умеет
пометить много сообщений `Published` одним statement'ом — см.
[Подтверждение батча одной записью](#подтверждение-батча-одной-записью).

| Метод | Описание |
|---|---|
| `markPublishedBatch(messages)` | Помечает каждое сообщение списка `Published`, как сделал бы `markPublished()` для каждого, за минимально возможное число записей. Пустой список — no-op |

### BatchSavingStorageInterface

Расширяет `StorageInterface`. Опционально: реализовать, когда бэкенд умеет
вставлять много строк одним запросом — см. [Сохранение батча одной записью](#сохранение-батча-одной-записью).

| Метод | Описание |
|---|---|
| `saveBatch(messages)` | Сохраняет каждое сообщение по контракту `save()`. Пустой список — no-op |

### RequeueableStorageInterface

Расширяет `StorageInterface`. Опционально — см. [Повторная постановка сбойных сообщений](#повторная-постановка-сбойных-сообщений).

| Метод | Описание |
|---|---|
| `findFailed(types = [], limit = 1000)` | `Failed`-сообщения, старые первыми там, где бэкенд держит порядок |
| `requeue(message)` | `Failed` → `Pending` с обнулёнными попытками (`OutboxMessage::withAttemptsReset()`). Возвращает `false`, ничего не трогая, если хранилище уже не держит сообщение как `Failed` |

### StatsAwareStorageInterface

Расширяет `StorageInterface`. Опционально — см. [Наблюдение за очередью](#наблюдение-за-очередью).

| Метод | Описание |
|---|---|
| `stats()` | Снимок `OutboxStats` |

### OutboxStats

| Свойство/Метод | Описание |
|---|---|
| `$pending`, `$processing`, `$published`, `$failed` | Счётчики; каждый неотрицательный |
| `$oldestPendingCreatedAt` | `?DateTimeImmutable`; `null`, когда ничего не ожидает или бэкенд это не отслеживает |
| `total()` | Сумма четырёх счётчиков |
| `countOf(status)` | Счётчик для case `OutboxStatus` |
| `oldestPendingAgeSeconds(now)` | Секунды с создания самого старого ожидающего сообщения, никогда не отрицательно; `null` без timestamp |

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
| `withAttemptsReset()` | Возвращает новый экземпляр как никогда не пытавшийся: `Pending`, ноль попыток, без последней попытки. То, что сохраняет requeue |

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
| `Failed` | `'failed'` | Терминальная неудача: повторы исчерпаны или паблишер объявил сбой терминальным. Сдвигается только requeue |

### RetryPolicy

| Метод | Описание |
|---|---|
| `__construct(maxAttempts, delaySeconds)` | По умолчанию: 3 попытки, задержка 60 с |
| `shouldRetry(message)` | Проверяет количество попыток |
| `isReadyForRetry(message, now)` | Проверяет попытки + истечение задержки |
| `readyThreshold(now)` | `now - delaySeconds`: та же проверка в виде границы, по которой может фильтровать хранилище. Питает `RetryAwareStorageInterface::claimReady()` |

### Processor

| Метод | Описание |
|---|---|
| `__construct(storage, publisher, retryPolicy, clock, batchSize, logger, types)` | Batch по умолчанию: 100. `types` (`list<string>`, по умолчанию `[]` = все типы) ограничивает, что забирает этот процессор — см. [Общее хранилище для нескольких потребителей](#общее-хранилище-для-нескольких-потребителей) |
| `process()` | Возвращает `ProcessingResult` |

### PublishException

| Метод | Описание |
|---|---|
| `__construct(message, outboxMessage, code = 0, previous = null, terminal = false)` | То, что паблишер бросает при сбое доставки; повторяется по `RetryPolicy` |
| `terminal(message, outboxMessage, code = 0, previous = null)` | Статическая фабрика для сбоя, который повтор не исправит; `Processor` сразу помечает сообщение `Failed` |
| `getOutboxMessage()`, `isTerminal()` | Аксессоры |

### ProcessingResult

| Свойство/Метод | Описание |
|---|---|
| `$published` | Количество успешно опубликованных сообщений |
| `$failed` | Количество сбоев публикации в этом запуске плюс сообщения, заклеймленные с исчерпанными попытками |
| `$skipped` | Количество захваченных сообщений, не готовых к повтору. `0` с `RetryAwareStorageInterface`, который их не захватывает |
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
