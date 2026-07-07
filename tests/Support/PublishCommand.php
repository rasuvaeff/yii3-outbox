<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox\Tests\Support;

use Rasuvaeff\PropertyTesting\StateMachine\Command;

/**
 * Model-based command: mark the message at $index Published (a no-op when fewer
 * than $index + 1 messages exist).
 */
final readonly class PublishCommand implements Command
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

        $model[$this->index] = 'published';

        return $model;
    }

    #[\Override]
    public function run(mixed $model, mixed $system): mixed
    {
        \assert($system instanceof OutboxHarness);

        $system->publish($this->index);

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
        return 'Publish(' . $this->index . ')';
    }
}
