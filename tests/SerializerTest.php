<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\OutboxStatus;
use Rasuvaeff\Yii3Outbox\Serializer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(Serializer::class)]
final class SerializerTest
{
    private Serializer $fixture;
    private OutboxMessage $message;

    #[BeforeTest]
    public function setUp(): void
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

    public function serializesAndDeserializesMessage(): void
    {
        $json = $this->fixture->serialize($this->message);
        $restored = $this->fixture->deserialize($json);

        Assert::same($restored->getId(), $this->message->getId());
        Assert::same($restored->getType(), $this->message->getType());
        Assert::same($restored->getPayload(), $this->message->getPayload());
        Assert::same($restored->getStatus(), $this->message->getStatus());
        Assert::same($restored->getAttempts(), $this->message->getAttempts());
        Assert::same($restored->getAggregateId(), $this->message->getAggregateId());
        Assert::same(
            $restored->getCreatedAt()->format(DATE_ATOM),
            $this->message->getCreatedAt()->format(DATE_ATOM),
        );
        Assert::same(
            $restored->getLastAttemptAt()?->format(DATE_ATOM),
            $this->message->getLastAttemptAt()?->format(DATE_ATOM),
        );
    }

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

        Assert::null($restored->getLastAttemptAt());
    }

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

        Assert::null($restored->getAggregateId());
    }

    public function producesValidJson(): void
    {
        $json = $this->fixture->serialize($this->message);
        $decoded = json_decode($json, true);

        Assert::true(is_array($decoded));
        Assert::same($decoded['id'], 'abc123');
        Assert::same($decoded['type'], 'order.created');
        Assert::same($decoded['status'], 'pending');
        Assert::same($decoded['aggregateId'], 'order-1');
    }

    public function throwsOnInvalidJson(): void
    {
        Expect::exception(InvalidArgumentException::class);

        $this->fixture->deserialize('not valid json');
    }

    public function throwsOnMissingRequiredField(): void
    {
        Expect::exception(InvalidArgumentException::class);

        $this->fixture->deserialize('{"type":"test"}');
    }

    public function throwsOnNonArrayData(): void
    {
        Expect::exception(InvalidArgumentException::class);

        $this->fixture->deserialize('"string"');
    }

    public function throwsOnNonStringId(): void
    {
        $json = '{"id":123,"type":"t","payload":"p","status":"pending","createdAt":"2026-01-01T00:00:00+00:00","attempts":0}';

        Expect::exception(InvalidArgumentException::class);

        $this->fixture->deserialize($json);
    }

    public function throwsOnNonStringStatus(): void
    {
        $json = '{"id":"a","type":"t","payload":"p","status":123,"createdAt":"2026-01-01T00:00:00+00:00","attempts":0}';

        Expect::exception(InvalidArgumentException::class);

        $this->fixture->deserialize($json);
    }

    public function throwsOnNonIntAttempts(): void
    {
        $json = '{"id":"a","type":"t","payload":"p","status":"pending","createdAt":"2026-01-01T00:00:00+00:00","attempts":"zero"}';

        Expect::exception(InvalidArgumentException::class);

        $this->fixture->deserialize($json);
    }

    public function throwsOnNonStringAggregateId(): void
    {
        $json = '{"id":"a","type":"t","payload":"p","status":"pending","createdAt":"2026-01-01T00:00:00+00:00","attempts":0,"aggregateId":123}';

        Expect::exception(InvalidArgumentException::class);

        $this->fixture->deserialize($json);
    }

    public function deserializesLegacyMessageWithoutAggregateId(): void
    {
        $json = '{"id":"a","type":"t","payload":"p","status":"pending","createdAt":"2026-01-01T00:00:00+00:00","attempts":0}';

        $restored = $this->fixture->deserialize($json);

        Assert::null($restored->getAggregateId());
    }

    public function throwsOnNonStringType(): void
    {
        $json = '{"id":"a","type":123,"payload":"p","status":"pending","createdAt":"2026-01-01T00:00:00+00:00","attempts":0}';

        Expect::exception(InvalidArgumentException::class);

        $this->fixture->deserialize($json);
    }

    public function throwsOnNonStringPayload(): void
    {
        $json = '{"id":"a","type":"t","payload":123,"status":"pending","createdAt":"2026-01-01T00:00:00+00:00","attempts":0}';

        Expect::exception(InvalidArgumentException::class);

        $this->fixture->deserialize($json);
    }

    public function throwsOnNonStringCreatedAt(): void
    {
        $json = '{"id":"a","type":"t","payload":"p","status":"pending","createdAt":123,"attempts":0}';

        Expect::exception(InvalidArgumentException::class);

        $this->fixture->deserialize($json);
    }

    public function throwsOnNonStringLastAttemptAt(): void
    {
        $json = '{"id":"a","type":"t","payload":"p","status":"pending","createdAt":"2026-01-01T00:00:00+00:00","attempts":0,"lastAttemptAt":123}';

        Expect::exception(InvalidArgumentException::class);

        $this->fixture->deserialize($json);
    }

    public function serializeThrowsWhenPayloadIsNotUtf8(): void
    {
        $message = OutboxMessage::create(type: 't', payload: "\xB1\x31");

        Expect::exception(InvalidArgumentException::class);

        $this->fixture->serialize($message);
    }
}
