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
            priority: 10,
        );

        Assert::same($draft->type, 'order.created');
        Assert::same($draft->payload, '{"orderId": 42}');
        Assert::same($draft->aggregateId, 'order-42');
        Assert::same($draft->id, 'order-created-42');
        Assert::same($draft->priority, 10);
    }

    public function aggregateIdAndIdDefaultToNullAndPriorityToZero(): void
    {
        $draft = new OutboxMessageDraft(type: 'order.created', payload: '{}');

        Assert::null($draft->aggregateId);
        Assert::null($draft->id);
        Assert::same($draft->priority, 0);
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

    public function throwsOnPriorityOutsideTheSmallintRange(): void
    {
        Assert::same((new OutboxMessageDraft(type: 'a', payload: '{}', priority: -32768))->priority, -32768);
        Assert::same((new OutboxMessageDraft(type: 'a', payload: '{}', priority: 32767))->priority, 32767);

        Expect::exception(InvalidArgumentException::class)->withMessage('Priority must be between -32768 and 32767');

        new OutboxMessageDraft(type: 'a', payload: '{}', priority: 32768);
    }

    public function throwsOnPriorityBelowTheSmallintRange(): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessage('Priority must be between -32768 and 32767');

        new OutboxMessageDraft(type: 'a', payload: '{}', priority: -32769);
    }

    public function acceptsAnEmptyPayload(): void
    {
        Assert::same((new OutboxMessageDraft(type: 'ping', payload: ''))->payload, '');
    }
}
