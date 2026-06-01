<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;

/**
 * @api
 */
final readonly class Serializer implements SerializerInterface
{
    #[\Override]
    public function serialize(OutboxMessage $message): string
    {
        $data = [
            'id' => $message->getId(),
            'type' => $message->getType(),
            'payload' => $message->getPayload(),
            'status' => $message->getStatus()->value,
            'createdAt' => $message->getCreatedAt()->format(DATE_ATOM),
            'attempts' => $message->getAttempts(),
            'lastAttemptAt' => $message->getLastAttemptAt()?->format(DATE_ATOM),
        ];

        try {
            return json_encode($data, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Failed to serialize message: ' . $e->getMessage());
        }
    }

    #[\Override]
    public function deserialize(string $data): OutboxMessage
    {
        try {
            $decoded = json_decode($data, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Failed to deserialize message: ' . $e->getMessage());
        }

        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Deserialized data must be an array');
        }

        $required = ['id', 'type', 'payload', 'status', 'createdAt', 'attempts'];
        foreach ($required as $field) {
            if (!array_key_exists($field, $decoded)) {
                throw new InvalidArgumentException('Missing required field: ' . $field);
            }
        }

        return new OutboxMessage(
            id: (string) $decoded['id'],
            type: (string) $decoded['type'],
            payload: (string) $decoded['payload'],
            status: OutboxStatus::from((string) $decoded['status']),
            createdAt: new DateTimeImmutable((string) $decoded['createdAt']),
            attempts: (int) $decoded['attempts'],
            lastAttemptAt: isset($decoded['lastAttemptAt'])
                ? new DateTimeImmutable((string) $decoded['lastAttemptAt'])
                : null,
        );
    }
}
