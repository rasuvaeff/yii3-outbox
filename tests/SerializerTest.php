<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\OutboxStatus;
use Rasuvaeff\Yii3Outbox\Serializer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
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
        $decoded = json_decode($json, associative: true);

        Assert::true(is_array($decoded));
        Assert::same($decoded['id'], 'abc123');
        Assert::same($decoded['type'], 'order.created');
        Assert::same($decoded['status'], 'pending');
        Assert::same($decoded['aggregateId'], 'order-1');
    }

    public function throwsOnInvalidJson(): void
    {
        try {
            $this->fixture->deserialize('not valid json');
            Assert::fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::true(str_starts_with($e->getMessage(), 'Failed to deserialize message: '));
            Assert::true(strlen($e->getMessage()) > strlen('Failed to deserialize message: '));
        }
    }

    public function throwsOnMissingRequiredField(): void
    {
        $json = '{"type":"t","payload":"p","status":"pending","createdAt":"2026-01-01T00:00:00+00:00","attempts":0}';

        try {
            $this->fixture->deserialize($json);
            Assert::fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('Missing required field: id');
        }
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

    public function legacyMessageWithoutPriorityDeserializesWithZero(): void
    {
        $json = '{"id":"a","type":"t","payload":"p","status":"pending","createdAt":"2026-01-01T00:00:00+00:00","attempts":0}';

        Assert::same($this->fixture->deserialize($json)->getPriority(), 0);
    }

    public function priorityRoundTrips(): void
    {
        $message = OutboxMessage::create(type: 'audit.entry', payload: '{}', priority: 10);

        Assert::same($this->fixture->deserialize($this->fixture->serialize($message))->getPriority(), 10);
    }

    public function throwsOnNonIntPriority(): void
    {
        $json = '{"id":"a","type":"t","payload":"p","status":"pending","createdAt":"2026-01-01T00:00:00+00:00","attempts":0,"priority":"10"}';

        Expect::exception(InvalidArgumentException::class)->withMessage('Field "priority" must be an integer');

        $this->fixture->deserialize($json);
    }

    public function throwsOnPriorityOutOfRange(): void
    {
        $json = '{"id":"a","type":"t","payload":"p","status":"pending","createdAt":"2026-01-01T00:00:00+00:00","attempts":0,"priority":40000}';

        Expect::exception(InvalidArgumentException::class);

        $this->fixture->deserialize($json);
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

    #[DataProvider('malformedFieldProvider')]
    public function rejectsMalformedFieldsWithInvalidArgument(string $field, string $value, string $expectedMessage): void
    {
        // Every rejection in this class is an InvalidArgumentException. A
        // malformed date used to escape as DateMalformedStringException and an
        // unknown status as a raw ValueError, so a caller catching "bad input"
        // had to know which field produced it.
        $decoded = [
            'id' => 'abc123',
            'type' => 'order.created',
            'payload' => '{}',
            'status' => 'pending',
            'createdAt' => '2026-01-15 12:30:00',
            'attempts' => 0,
        ];
        $decoded[$field] = $value;

        Expect::exception(InvalidArgumentException::class)->withMessageContaining($expectedMessage);

        $this->fixture->deserialize((string) json_encode($decoded));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function malformedFieldProvider(): iterable
    {
        yield 'unparsable createdAt' => ['createdAt', 'not-a-date', 'Field "createdAt" is not a valid datetime'];
        yield 'empty createdAt' => ['createdAt', '', 'Field "createdAt" is not a valid datetime'];
        yield 'unparsable lastAttemptAt' => ['lastAttemptAt', 'yesterday-ish', 'Field "lastAttemptAt" is not a valid datetime'];
        yield 'unknown status' => ['status', 'archived', 'Field "status" has an unknown value "archived"'];
        yield 'empty status' => ['status', '', 'Field "status" has an unknown value'];
    }

    /**
     * Deserialization is the boundary a storage backend hands untrusted rows
     * to. Whatever the input, it either returns a message or fails with the
     * one exception type the package documents — never a raw ValueError or
     * DateMalformedStringException from a field it forgot to guard.
     */
    #[Property(runs: 300, timeoutMs: 1000)]
    public function deserializeEitherReturnsAMessageOrThrowsInvalidArgument(string $data): void
    {
        $accepted = false;

        try {
            $this->fixture->deserialize($data);
            $accepted = true;
        } catch (InvalidArgumentException) {
        }

        Classify::cover($accepted, 'accepted', 5.0);
        Classify::cover(!$accepted, 'rejected', 20.0);

        Assert::true(actual: true);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function deserializeEitherReturnsAMessageOrThrowsInvalidArgumentGenerators(): array
    {
        $field = static fn(ArbitraryInterface $value): ArbitraryInterface => Gen::frequency([
            [3, $value],
            [1, Gen::elements(['', 'not-a-date', '42', 'null'])],
        ]);

        return [
            'data' => Gen::frequency([
                // Well-formed envelopes with individually plausible or corrupt
                // fields: the paths that reach the date and status parsing.
                [6, Gen::map(
                    Gen::record([
                        'id' => $field(Gen::stringFrom('abcdef0123456789', minLength: 1, maxLength: 12)),
                        'type' => $field(Gen::elements(['order.created', 'ab.exposure'])),
                        'payload' => $field(Gen::constant('{}')),
                        'status' => $field(Gen::elements(['pending', 'processing', 'published', 'failed'])),
                        'createdAt' => $field(Gen::map(Gen::datetime(), static fn(\DateTimeImmutable $d): string => $d->format(DATE_ATOM))),
                        'attempts' => Gen::intBetween(0, 5),
                        'lastAttemptAt' => Gen::nullable(
                            $field(Gen::map(Gen::datetime(), static fn(\DateTimeImmutable $d): string => $d->format(DATE_ATOM))),
                        ),
                    ]),
                    static fn(array $decoded): string => (string) json_encode($decoded),
                )],
                // Arbitrary JSON and arbitrary bytes: the paths that reach the
                // shape and type guards.
                [2, Gen::jsonString()],
                [2, Gen::stringAscii()],
            ]),
        ];
    }

    public function serializeThrowsWhenPayloadIsNotUtf8(): void
    {
        $message = OutboxMessage::create(type: 't', payload: "\xB1\x31");

        try {
            $this->fixture->serialize($message);
            Assert::fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('Failed to serialize message: Malformed');
        }
    }
}
