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

    public function findPending(int $limit = 100): array
    {
        return array_values(
            array_filter(
                $this->messages,
                fn(OutboxMessage $m) => $m->getStatus() === OutboxStatus::Pending,
            ),
        );
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
};

$message = OutboxMessage::create(type: 'user.registered', payload: '{"userId": 1}');
$storage->save($message);
$storage->markPublished($message);
