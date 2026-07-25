<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\OutboxStatus;
use Rasuvaeff\Yii3Outbox\StorageInterface;

$storage = new class implements StorageInterface {
    /** @var array<string, OutboxMessage> */
    private array $messages = [];

    public function save(OutboxMessage $message): void
    {
        $this->messages[$message->getId()] = $message;
        echo "[Storage] Saved: {$message->getId()}\n";
    }

    public function findPending(array $types = [], int $limit = 1000): array
    {
        return array_slice($this->pending($types), 0, $limit);
    }

    /**
     * Concurrent workers must claim instead of reading: the transition to
     * Processing is what stops two workers from publishing the same message.
     */
    public function claim(array $types = [], int $limit = 1000): array
    {
        $claimed = [];

        foreach (array_slice($this->pending($types), 0, $limit) as $message) {
            $processing = $message->withStatus(OutboxStatus::Processing);
            $this->messages[$message->getId()] = $processing;
            $claimed[] = $processing;
        }

        return $claimed;
    }

    public function markPublished(OutboxMessage $message): void
    {
        $this->messages[$message->getId()] = $message->withStatus(OutboxStatus::Published);
        echo "[Storage] Marked published: {$message->getId()}\n";
    }

    public function markFailed(OutboxMessage $message): void
    {
        $this->messages[$message->getId()] = $message->withStatus(OutboxStatus::Failed);
        echo "[Storage] Marked failed: {$message->getId()}\n";
    }

    public function getById(string $id): ?OutboxMessage
    {
        return $this->messages[$id] ?? null;
    }

    /**
     * @param list<string> $types
     *
     * @return list<OutboxMessage>
     */
    private function pending(array $types): array
    {
        return array_values(
            array_filter(
                $this->messages,
                static fn(OutboxMessage $m): bool => $m->getStatus() === OutboxStatus::Pending
                    && ($types === [] || in_array($m->getType(), $types, true)),
            ),
        );
    }
};

$message = OutboxMessage::create(type: 'user.registered', payload: '{"userId": 1}');
$storage->save($message);

// a message that mirrors a domain event carries that event's id, so a
// republish cannot produce a second id downstream
$mirrored = OutboxMessage::create(
    type: 'user.registered',
    payload: '{"userId": 2}',
    id: 'user-registered-2',
);
$storage->save($mirrored);

echo '[Storage] Claimed: ' . count($storage->claim(types: ['user.registered'])) . "\n";
$storage->markPublished($message);
$storage->markPublished($mirrored);
