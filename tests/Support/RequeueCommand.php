<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox\Tests\Support;

use Rasuvaeff\PropertyTesting\StateMachine\Command;

/**
 * Model-based command: requeue the message at $index. Only a Failed message
 * moves (back to Pending); any other status, or an index past the end, is a
 * no-op.
 */
final readonly class RequeueCommand implements Command
{
    public function __construct(private int $index) {}

    #[\Override]
    public function preCondition(mixed $model): bool
    {
        return true;
    }

    #[\Override]
    public function nextState(mixed $model): mixed
    {
        \assert(is_array($model));

        if ($this->index < 0 || $this->index >= count($model)) {
            return $model;
        }

        if ($model[$this->index] === 'failed') {
            $model[$this->index] = 'pending';
        }

        return $model;
    }

    #[\Override]
    public function run(mixed $model, mixed $system): mixed
    {
        \assert($system instanceof OutboxHarness);

        $system->requeue($this->index);

        return $system->statuses();
    }

    #[\Override]
    public function postCondition(mixed $model, mixed $result): bool
    {
        return $result === $this->nextState($model);
    }

    #[\Override]
    public function __toString(): string
    {
        return 'Requeue(' . $this->index . ')';
    }
}
