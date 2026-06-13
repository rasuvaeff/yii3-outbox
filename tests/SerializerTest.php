<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\OutboxStatus;
use Rasuvaeff\Yii3Outbox\Serializer;

#[CoversClass(Serializer::class)]
final class SerializerTest extends TestCase
{
    private Serializer $fixture;
    private OutboxMessage $message;

    #[\Override]
    protected function setUp(): void
    {
        $this->fixture = new Serializer();
        $this->message = new OutboxMessage(
            id: 'abc123',
            type: 'order.created',
            payload: '{"orderId": 1}',
            status: OutboxStatus::Pending,
            createdAt: new DateTimeImmutable('2026-01-15 12:30:00+00:00'),
            attempts: 2,
            lastAttemptAt: new DateTimeImmutable('2026-01-15 12:31:00+00:00'),
            aggregateId: 'order-1',
        );
    }

    #[Test]
    public function serializesAndDeserializesMessage(): void
    {
        $json = $this->fixture->serialize($this->message);
        $restored = $this->fixture->deserialize($json);

        $this->assertSame($this->message->getId(), $restored->getId());
        $this->assertSame($this->message->getType(), $restored->getType());
        $this->assertSame($this->message->getPayload(), $restored->getPayload());
        $this->assertSame($this->message->getStatus(), $restored->getStatus());
        $this->assertSame($this->message->getAttempts(), $restored->getAttempts());
        $this->assertSame($this->message->getAggregateId(), $restored->getAggregateId());
        $this->assertSame(
            $this->message->getCreatedAt()->format(DATE_ATOM),
            $restored->getCreatedAt()->format(DATE_ATOM),
        );
        $this->assertSame(
            $this->message->getLastAttemptAt()?->format(DATE_ATOM),
            $restored->getLastAttemptAt()?->format(DATE_ATOM),
        );
    }

    #[Test]
    public function serializesWithoutLastAttemptAt(): void
    {
        $message = new OutboxMessage(
            id: 'abc123',
            type: 'test',
            payload: '{}',
            status: OutboxStatus::Pending,
            createdAt: new DateTimeImmutable('2026-01-15 12:30:00'),
        );

        $json = $this->fixture->serialize($message);
        $restored = $this->fixture->deserialize($json);

        $this->assertNull($restored->getLastAttemptAt());
    }

    #[Test]
    public function serializesWithNullAggregateId(): void
    {
        $message = new OutboxMessage(
            id: 'abc123',
            type: 'test',
            payload: '{}',
            status: OutboxStatus::Pending,
            createdAt: new DateTimeImmutable('2026-01-15 12:30:00'),
        );

        $json = $this->fixture->serialize($message);
        $restored = $this->fixture->deserialize($json);

        $this->assertNull($restored->getAggregateId());
    }

    #[Test]
    public function producesValidJson(): void
    {
        $json = $this->fixture->serialize($this->message);
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded);
        $this->assertSame('abc123', $decoded['id']);
        $this->assertSame('order.created', $decoded['type']);
        $this->assertSame('pending', $decoded['status']);
        $this->assertSame('order-1', $decoded['aggregateId']);
    }

    #[Test]
    public function throwsOnInvalidJson(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Failed to deserialize message: Syntax error');

        $this->fixture->deserialize('not valid json');
    }

    #[Test]
    public function throwsOnMissingRequiredField(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required field: id');

        $this->fixture->deserialize('{"type":"test"}');
    }

    #[Test]
    public function throwsOnNonArrayData(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Deserialized data must be an array');

        $this->fixture->deserialize('"string"');
    }

    #[Test]
    public function throwsOnNonStringId(): void
    {
        $json = '{"id":123,"type":"t","payload":"p","status":"pending","createdAt":"2026-01-01T00:00:00+00:00","attempts":0}';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Field "id" must be a string');

        $this->fixture->deserialize($json);
    }

    #[Test]
    public function throwsOnNonStringStatus(): void
    {
        $json = '{"id":"a","type":"t","payload":"p","status":123,"createdAt":"2026-01-01T00:00:00+00:00","attempts":0}';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Field "status" must be a string');

        $this->fixture->deserialize($json);
    }

    #[Test]
    public function throwsOnNonIntAttempts(): void
    {
        $json = '{"id":"a","type":"t","payload":"p","status":"pending","createdAt":"2026-01-01T00:00:00+00:00","attempts":"zero"}';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Field "attempts" must be an integer');

        $this->fixture->deserialize($json);
    }

    #[Test]
    public function throwsOnNonStringAggregateId(): void
    {
        $json = '{"id":"a","type":"t","payload":"p","status":"pending","createdAt":"2026-01-01T00:00:00+00:00","attempts":0,"aggregateId":123}';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Field "aggregateId" must be a string or null');

        $this->fixture->deserialize($json);
    }

    #[Test]
    public function deserializesLegacyMessageWithoutAggregateId(): void
    {
        $json = '{"id":"a","type":"t","payload":"p","status":"pending","createdAt":"2026-01-01T00:00:00+00:00","attempts":0}';

        $restored = $this->fixture->deserialize($json);

        $this->assertNull($restored->getAggregateId());
    }

    #[Test]
    public function throwsOnNonStringType(): void
    {
        $json = '{"id":"a","type":123,"payload":"p","status":"pending","createdAt":"2026-01-01T00:00:00+00:00","attempts":0}';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Field "type" must be a string');

        $this->fixture->deserialize($json);
    }

    #[Test]
    public function throwsOnNonStringPayload(): void
    {
        $json = '{"id":"a","type":"t","payload":123,"status":"pending","createdAt":"2026-01-01T00:00:00+00:00","attempts":0}';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Field "payload" must be a string');

        $this->fixture->deserialize($json);
    }

    #[Test]
    public function throwsOnNonStringCreatedAt(): void
    {
        $json = '{"id":"a","type":"t","payload":"p","status":"pending","createdAt":123,"attempts":0}';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Field "createdAt" must be a string');

        $this->fixture->deserialize($json);
    }

    #[Test]
    public function throwsOnNonStringLastAttemptAt(): void
    {
        $json = '{"id":"a","type":"t","payload":"p","status":"pending","createdAt":"2026-01-01T00:00:00+00:00","attempts":0,"lastAttemptAt":123}';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Field "lastAttemptAt" must be a string');

        $this->fixture->deserialize($json);
    }

    #[Test]
    public function serializeThrowsWhenPayloadIsNotUtf8(): void
    {
        $message = OutboxMessage::create(type: 't', payload: "\xB1\x31"); // invalid UTF-8

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Failed to serialize message: Malformed UTF-8 characters');

        $this->fixture->serialize($message);
    }
}
