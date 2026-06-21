<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox\Benchmarks;

use DateTimeImmutable;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\OutboxStatus;
use Rasuvaeff\Yii3Outbox\Serializer;
use Testo\Bench;

/**
 * Compares Serializer round-trip cost for a small payload vs a large payload.
 */
final class SerializerBench
{
    private static Serializer $serializer;
    private static OutboxMessage $smallMessage;
    private static OutboxMessage $largeMessage;
    private static string $smallJson;
    private static string $largeJson;

    private static function init(): void
    {
        self::$serializer ??= new Serializer();

        self::$smallMessage ??= new OutboxMessage(
            id: 'aabbccdd-0011-2233-4455-667788990011',
            type: 'user.registered',
            payload: '{"userId":42}',
            status: OutboxStatus::Pending,
            createdAt: new DateTimeImmutable('2024-01-15T10:00:00+00:00'),
        );

        self::$largeMessage ??= new OutboxMessage(
            id: 'aabbccdd-0011-2233-4455-667788990022',
            type: 'order.placed',
            payload: json_encode([
                'orderId' => 'ord-9999',
                'items' => array_fill(0, 20, ['sku' => 'ITEM-001', 'qty' => 2, 'price' => 19.99]),
                'customer' => ['id' => 77, 'email' => 'user@example.com', 'country' => 'US'],
                'meta' => ['source' => 'web', 'campaign' => 'summer-sale-2024'],
            ], JSON_THROW_ON_ERROR),
            status: OutboxStatus::Pending,
            createdAt: new DateTimeImmutable('2024-01-15T10:00:00+00:00'),
            aggregateId: 'ord-9999',
        );

        self::$smallJson ??= self::$serializer->serialize(self::$smallMessage);
        self::$largeJson ??= self::$serializer->serialize(self::$largeMessage);
    }

    #[Bench(
        callables: [
            'large-payload' => [self::class, 'roundTripLarge'],
        ],
        calls: 1_000,
        iterations: 10,
    )]
    public static function roundTripSmall(): OutboxMessage
    {
        self::init();
        $json = self::$serializer->serialize(self::$smallMessage);

        return self::$serializer->deserialize($json);
    }

    public static function roundTripLarge(): OutboxMessage
    {
        self::init();
        $json = self::$serializer->serialize(self::$largeMessage);

        return self::$serializer->deserialize($json);
    }
}
