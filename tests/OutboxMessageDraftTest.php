<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox\Tests;

use InvalidArgumentException;
use Rasuvaeff\Yii3Outbox\OutboxMessageDraft;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(OutboxMessageDraft::class)]
final class OutboxMessageDraftTest
{
    public function holdsEveryField(): void
    {
        $draft = new OutboxMessageDraft(
            type: 'order.created',
            payload: '{"orderId": 42}',
            aggregateId: 'order-42',
            id: 'order-created-42',
        );

        Assert::same($draft->type, 'order.created');
        Assert::same($draft->payload, '{"orderId": 42}');
        Assert::same($draft->aggregateId, 'order-42');
        Assert::same($draft->id, 'order-created-42');
    }

    public function aggregateIdAndIdDefaultToNull(): void
    {
        $draft = new OutboxMessageDraft(type: 'order.created', payload: '{}');

        Assert::null($draft->aggregateId);
        Assert::null($draft->id);
    }

    public function throwsOnEmptyType(): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessage('Message type must not be empty');

        new OutboxMessageDraft(type: '', payload: '{}');
    }

    public function throwsOnEmptyId(): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessage('Message id must not be empty');

        new OutboxMessageDraft(type: 'order.created', payload: '{}', id: '');
    }

    public function acceptsAnEmptyPayload(): void
    {
        Assert::same((new OutboxMessageDraft(type: 'ping', payload: ''))->payload, '');
    }
}
