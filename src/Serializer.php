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
            'aggregateId' => $message->getAggregateId(),
        ];

        try {
            return json_encode($data, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Failed to serialize message: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    #[\Override]
    public function deserialize(string $data): OutboxMessage
    {
        try {
            $decoded = json_decode($data, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Failed to deserialize message: ' . $e->getMessage(), $e->getCode(), $e);
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

        if (!is_string($decoded['id'])) {
            throw new InvalidArgumentException('Field "id" must be a string');
        }

        if (!is_string($decoded['type'])) {
            throw new InvalidArgumentException('Field "type" must be a string');
        }

        if (!is_string($decoded['payload'])) {
            throw new InvalidArgumentException('Field "payload" must be a string');
        }

        if (!is_string($decoded['status'])) {
            throw new InvalidArgumentException('Field "status" must be a string');
        }

        if (!is_string($decoded['createdAt'])) {
            throw new InvalidArgumentException('Field "createdAt" must be a string');
        }

        if (!is_int($decoded['attempts'])) {
            throw new InvalidArgumentException('Field "attempts" must be an integer');
        }

        $lastAttemptAt = null;

        if (isset($decoded['lastAttemptAt'])) {
            if (!is_string($decoded['lastAttemptAt'])) {
                throw new InvalidArgumentException('Field "lastAttemptAt" must be a string');
            }

            $lastAttemptAt = $this->parseDateTime($decoded['lastAttemptAt'], 'lastAttemptAt');
        }

        $aggregateId = null;

        if (array_key_exists('aggregateId', $decoded) && $decoded['aggregateId'] !== null) {
            if (!is_string($decoded['aggregateId'])) {
                throw new InvalidArgumentException('Field "aggregateId" must be a string or null');
            }

            $aggregateId = $decoded['aggregateId'];
        }

        $status = OutboxStatus::tryFrom($decoded['status']);

        if ($status === null) {
            throw new InvalidArgumentException(sprintf('Field "status" has an unknown value "%s"', $decoded['status']));
        }

        return new OutboxMessage(
            id: $decoded['id'],
            type: $decoded['type'],
            payload: $decoded['payload'],
            status: $status,
            createdAt: $this->parseDateTime($decoded['createdAt'], 'createdAt'),
            attempts: $decoded['attempts'],
            lastAttemptAt: $lastAttemptAt,
            aggregateId: $aggregateId,
        );
    }

    /**
     * Every other rejection in this class is an `InvalidArgumentException`;
     * a malformed date would otherwise escape as a raw
     * {@see \DateMalformedStringException}, forcing a caller that catches
     * "bad input" to know which field it came from.
     */
    private function parseDateTime(string $value, string $field): DateTimeImmutable
    {
        // An empty string is a valid DateTimeImmutable input meaning "now" — a
        // corrupt row would deserialize into a message stamped with the time it
        // was read.
        if ($value === '') {
            throw new InvalidArgumentException(sprintf('Field "%s" is not a valid datetime', $field));
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception $e) {
            throw new InvalidArgumentException(sprintf('Field "%s" is not a valid datetime: %s', $field, $value), (int) $e->getCode(), previous: $e);
        }
    }
}
