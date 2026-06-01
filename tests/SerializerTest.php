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
            createdAt: new DateTimeImmutable('2026-01-15 12:30:00'),
            attempts: 2,
            lastAttemptAt: new DateTimeImmutable('2026-01-15 12:31:00'),
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
        $this->assertEquals($this->message->getCreatedAt(), $restored->getCreatedAt());
        $this->assertEquals($this->message->getLastAttemptAt(), $restored->getLastAttemptAt());
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
    public function producesValidJson(): void
    {
        $json = $this->fixture->serialize($this->message);
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded);
        $this->assertSame('abc123', $decoded['id']);
        $this->assertSame('order.created', $decoded['type']);
        $this->assertSame('pending', $decoded['status']);
    }

    #[Test]
    public function throwsOnInvalidJson(): void
    {
        $this->expectException(\InvalidArgumentException::class);

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
}
