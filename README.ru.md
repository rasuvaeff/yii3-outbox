# Расуваефф/yii3-исходящие
[![Stable Version](https://poser.pugx.org/rasuvaeff/yii3-outbox/v/stable)](https://packagist.org/packages/rasuvaeff/yii3-outbox)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/yii3-outbox/downloads)](https://packagist.org/packages/rasuvaeff/yii3-outbox)
[![Build](https://github.com/rasuvaeff/yii3-outbox/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/yii3-outbox/actions)
[![Static analysis](https://github.com/rasuvaeff/yii3-outbox/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/yii3-outbox/actions)
[![Psalm Level](https://shepherd.dev/github/rasuvaeff/yii3-outbox/level.svg)](https://shepherd.dev/github/rasuvaeff/yii3-outbox)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/yii3-outbox/php)](https://packagist.org/packages/rasuvaeff/yii3-outbox)
[![License](https://poser.pugx.org/rasuvaeff/yii3-outbox/license)](https://packagist.org/packages/rasuvaeff/yii3-outbox)
Реализация шаблона исходящих транзакций для Yii3. Предоставляет ядро ​​
 без сохранения состояния для надежной публикации сообщений с настраиваемыми политиками повтора.

 > Используете помощника по программированию с искусственным интеллектом? [llms.txt](llms.txt) содержит компактную ссылку на API, которую вы можете использовать. @@ЛИНИЯ@@
## Требования
- PHP 8.3+
 - `psr/lock` ^1.0
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
### Реализация издателя
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
### Обработка исходящих сообщений
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
### Повторить попытку
При сбое публикации:
 — если количество попыток < `maxAttempts` → сообщение остаётся `Pending`, будет повторено через `delaySeconds`
 - Если попыток >= `maxAttempts` → сообщение помечено как `Failed` (терминал)

```php
$policy = new RetryPolicy(maxAttempts: 3, delaySeconds: 60);

$policy->shouldRetry($message);          // bool — attempts remaining?
$policy->isReadyForRetry($message, $now); // bool — delay elapsed?
```
### Использование InMemoryStorage для тестов
```php
use Rasuvaeff\Yii3Outbox\InMemoryStorage;

$storage = new InMemoryStorage();
$storage->save($message);

$pending = $storage->findPending();
$storage->count();
$storage->clear();
```
## Справочник по API
### Исходящие
| Метод | Описание |
 |---|---|
 | `__construct(хранилище, часы)` | Основная точка входа |
 | `запись (тип, полезная нагрузка, агрегатный идентификатор?)` | Создать и сохранить сообщение, возвращает OutboxMessage | @@ЛИНИЯ@@
### ИсходящиеСообщение
| Метод | Описание |
 |---|---|
 | `create(type, payload,gregId?, CreateAt?)` | Фабрика с автоматически сгенерированным идентификатором |
 | `getId()` | Идентификатор сообщения (32-значный шестнадцатеричный код) |
 | `getType()` | Тип сообщения |
 | `getPayload()` | Необработанная строка полезной нагрузки |
 | `getStatus()` | Перечисление `OutboxStatus` |
 | `getCreatedAt()` | `DateTimeImmutable` |
 | `getAttempts()` | Количество попыток публикации |
 | `getLastAttemptAt()` | `?DateTimeImmutable` |
 | `getAggregateId()` | `?строка` |
 | `withStatus(статус)` | Возвращает новый экземпляр со статусом |
 | `сПопытка(в)` | Возвращает новый экземпляр с увеличенными попытками и отметкой времени | @@ЛИНИЯ@@
### Статус исходящих сообщений
| Дело | Значение |
 |---|---|
 | `В ожидании` | `'ожидает'` |
 | `Опубликовано` | `'опубликовано'` |
 | `Не удалось` | `'не удалось'` | @@ЛИНИЯ@@
### Политика повтора
| Метод | Описание |
 |---|---|
 | `__construct(maxAttempts,laySeconds)` | По умолчанию: 3 попытки, задержка 60 с |
 | `следуетПовторить(сообщение)` | Проверяет количество попыток |
 | `isReadyForRetry(сообщение, сейчас)` | Проверяет попытки + истекшая задержка | @@ЛИНИЯ@@
### Процессор
| Метод | Описание |
 |---|---|
 | `__construct(хранилище, издатель, retryPolicy, часы, пакетный размер, регистратор)` | Пакет по умолчанию: 100 |
 | `процесс()` | Возвращает `ProcessingResult` | @@ЛИНИЯ@@
### Результат обработки
| Свойство/Метод | Описание |
 |---|---|
 | `$опубликовано` | Количество успешно опубликованных сообщений |
 | `$не удалось` | Число исключений публикации в этом запуске |
 | `$пропущено` | Количество сообщений, не готовых к повтору |
 | `всего()` | Сумма всех счетчиков | @@ЛИНИЯ@@
### Сериализатор
| Метод | Описание |
 |---|---|
 | `сериализовать(сообщение)` | Сообщение в формате JSON |
 | `десериализовать(данные)` | JSON в сообщение | @@ЛИНИЯ@@
## Безопасность
- Реализации хранилища должны использовать параметризованные запросы для всех пользовательских значений.
 — полезные данные сообщения сохраняются как есть; при необходимости проверьте перед сохранением. @@ЛИНИЯ@@
## Примеры
См. [examples/](examples/) для полных примеров использования. @@ЛИНИЯ@@
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
`make test-coverage` и `makemutation` загружают `pcov` внутри контейнера
 `composer:2`, поскольку базовый образ не имеет драйвера покрытия. @@ЛИНИЯ@@
## Лицензия
BSD-3-пункт. См. [LICENSE.md](LICENSE.md).
